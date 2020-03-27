<?php


namespace App\Http\Controllers;

use App\Gateway\EventLogGateway;
use App\Gateway\QuestionGateway;
use App\Gateway\UserGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Log\Logger;
use LINE\LINEBot;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
use LINE\LINEBot\MessageBuilder\MultiMessageBuilder;
use LINE\LINEBot\MessageBuilder\StickerMessageBuilder;
use LINE\LINEBot\MessageBuilder\TemplateBuilder\ButtonTemplateBuilder;
use LINE\LINEBot\MessageBuilder\TemplateMessageBuilder;
use LINE\LINEBot\MessageBuilder\TextMessageBuilder;
use LINE\LINEBot\TemplateActionBuilder\MessageTemplateActionBuilder;


class Webhook extends Controller
{
    /**
     * @var LINEBot
     */
    private $bot;
    /**
     * @var Request
     */
    private $request;
    /**
     * @var Response
     */
    private $response;
    /**
     * @var Logger
     */
    private $logger;
    /**
     * @var EventLogGateway
     */
    private $logGateway;
    /**
     * @var UserGateway
     */
    private $userGateway;
    /**
     * @var QuestionGateway
     */
    private $questionGateway;
    /**
     * @var array
     */
    private $user;


    public function __construct(
        Request $request,
        Response $response,
        Logger $logger,
        EventLogGateway $logGateway,
        UserGateway $userGateway,
        QuestionGateway $questionGateway
    )
    {
        $this->request = $request;
        $this->response = $response;
        $this->logger = $logger;
        $this->logGateway = $logGateway;
        $this->userGateway = $userGateway;
        $this->questionGateway = $questionGateway;

        // create bot object
        $httpClient = new CurlHTTPClient(getenv('CHANNEL_ACCESS_TOKEN'));
        $this->bot = new LINEBot($httpClient, ['channelSecret' => getenv('CHANNEL_SECRET')]);
    }
    public function __invoke()
    {
        // get request
        $body = $this->request->all();

        // debuging data
        $this->logger->debug('Body', $body);

        // save log
        $signature = $this->request->server('HTTP_X_LINE_SIGNATURE') ?: '-';
        $this->logGateway->saveLog($signature, json_encode($body, true));

        return $this->handleEvents();
    }

    private function handleEvents()
    {
        $data = $this->request->all();

        if(is_array($data['events'])){
            foreach ($data['events'] as $event)
            {
                // skip group and room event
                if(! isset($event['source']['userId'])) continue;

                // get user data from database
                $this->user = $this->userGateway->getUser($event['source']['userId']);

                // if user not registered
                if(!$this->user) $this->followCallback($event);
                else {
                    // respond event
                    if($event['type'] == 'message'){
                        if(method_exists($this, $event['message']['type'].'Message')){
                            $this->{$event['message']['type'].'Message'}($event);
                        }
                    } else {
                        if(method_exists($this, $event['type'].'Callback')){
                            $this->{$event['type'].'Callback'}($event);
                        }
                    }
                }
            }
        }


        $this->response->setContent("No events found!");
        $this->response->setStatusCode(200);
        return $this->response;
    }

	//ADD FRIENDS
    private function followCallback($event)
    {
        $res = $this->bot->getProfile($event['source']['userId']);
        if ($res->isSucceeded())
        {
            $profile = $res->getJSONDecodedBody();

            // create welcome message
            $message1  = "Salam kenal, " . $profile['displayName'] . "!\n";
			$message2  = "Selamat datang di Tebak Judul Lagu \n";
			$message3  = "Bot ini merupakan kuis tebak lagu yang dapat digunakan oleh siapa saja yang ingin mengasah pengetahuannya tentang judul lagu terutama lagu Indonesia.\n";
			$message  = "Bot akan memunculkan sepenggal lirik lagu dan " . $profile['displayName'] . "silahkan menjawab judul lagu pada pilihan yang tersedia.\n";
            $message .= "Untuk bermain, silakan kirim pesan \"MULAI\" untuk memulai Kuis Tebak Lagu.";
            $textMessageBuilder1 = new TextMessageBuilder($message1);
			$textMessageBuilder2 = new TextMessageBuilder($message2);
			$textMessageBuilder3 = new TextMessageBuilder($message3);
			$textMessageBuilder4 = new TextMessageBuilder($message);

            // create sticker message
            $stickerMessageBuilder = new StickerMessageBuilder(1, 3);

            // merge all message
            $multiMessageBuilder = new MultiMessageBuilder();
            $multiMessageBuilder->add($textMessageBuilder1);
			$multiMessageBuilder->add($textMessageBuilder2);
			$multiMessageBuilder->add($textMessageBuilder3);
			$multiMessageBuilder->add($textMessageBuilder4);
            $multiMessageBuilder->add($stickerMessageBuilder);

            // send reply message
            $this->bot->replyMessage($event['replyToken'], $multiMessageBuilder);

            // save user data
            $this->userGateway->saveUser(
                $profile['userId'],
                $profile['displayName']
            );

        }
    }

	//RESPON TEKS DARI USER
    private function textMessage($event)
    {
        $userMessage = $event['message']['text'];
        if($this->user['number'] == 0)
        {
            if(strtolower($userMessage) == 'mulai')
            {
                // reset score
                $this->userGateway->setScore($this->user['user_id'], 0);
                // update number progress
                $this->userGateway->setUserProgress($this->user['user_id'], 1);
                // send question no.1
                $this->sendQuestion($event['replyToken'], 1);
            } else {
				$message = 'Maaf, kami tidak dapat memahami pesan Anda. Silakan ketik "MULAI" untuk memulai kuis.';
                $textMessageBuilder = new TextMessageBuilder($message);
                $this->bot->replyMessage($event['replyToken'], $textMessageBuilder);
            }

            // if user already begin test
        } else {
            $this->checkAnswer($userMessage, $event['replyToken']);
        }
    }

	//RESPON STICKER DARI USER
    private function stickerMessage($event)
    {
        // create sticker message
        $stickerMessageBuilder = new StickerMessageBuilder(1, 106);

        // create text message
		$$message = 'Maaf, kami tidak dapat memahami stiker Anda. Silakan ketik "MULAI" untuk memulai kuis.';
        $textMessageBuilder = new TextMessageBuilder($message);

        // merge all message
        $multiMessageBuilder = new MultiMessageBuilder();
        $multiMessageBuilder->add($stickerMessageBuilder);
        $multiMessageBuilder->add($textMessageBuilder);

        // send message
        $this->bot->replyMessage($event['replyToken'], $multiMessageBuilder);
    }

	//MENGIRIM PERTANYAAN
    private function sendQuestion($replyToken, $questionNum=1)
    {
        // get question from database
        $question = $this->questionGateway->getQuestion($questionNum);

        // prepare answer options
        for($opsi = "a"; $opsi <= "d"; $opsi++) {
            if(!empty($question['option_'.$opsi]))
                $options[] = new MessageTemplateActionBuilder($question['option_'.$opsi], $question['option_'.$opsi]);
        }

        // prepare button template
        $buttonTemplate = new ButtonTemplateBuilder($question['number']."/10", $question['text'], $question['image'], $options);

        // build message
        $messageBuilder = new TemplateMessageBuilder("Gunakan mobile app untuk melihat soal", $buttonTemplate);

        // send message
        $response = $this->bot->replyMessage($replyToken, $messageBuilder);
    }

	//CEK PERTANYAAN
    private function checkAnswer($message, $replyToken)
    {
        // if answer is true, increment score
        if($this->questionGateway->isAnswerEqual($this->user['number'], $message)){
            $this->user['score']++;
            $this->userGateway->setScore($this->user['user_id'], $this->user['score']);
        }

        if($this->user['number'] < 10)
        {
            // update number progress
            $this->userGateway->setUserProgress($this->user['user_id'], $this->user['number'] + 1);

            // send next question
            $this->sendQuestion($replyToken, $this->user['number'] + 1);
        }
        else {
            // create user score message
            $message = 'Skormu '. $this->user['score'];
            $textMessageBuilder1 = new TextMessageBuilder($message);

            // create sticker message
            $stickerId = ($this->user['score'] < 8) ? 100 : 114;
            $stickerMessageBuilder = new StickerMessageBuilder(1, $stickerId);

            // create play again message
            $message = ($this->user['score'] < 8) ?
                'Sudah Menyerah? Jika belum menyerah silahkan coba lagi. Ketik "MULAI" untuk bermain lagi!':
                'Mantap gan! Jika ingin bermain lagi silahkan Ketik "MULAI" untuk bermain lagi!';
            $textMessageBuilder2 = new TextMessageBuilder($message);

            // merge all message
            $multiMessageBuilder = new MultiMessageBuilder();
            $multiMessageBuilder->add($textMessageBuilder1);
            $multiMessageBuilder->add($stickerMessageBuilder);
            $multiMessageBuilder->add($textMessageBuilder2);

            // send reply message
            $this->bot->replyMessage($replyToken, $multiMessageBuilder);
            $this->userGateway->setUserProgress($this->user['user_id'], 0);
        }
    }
}

?>