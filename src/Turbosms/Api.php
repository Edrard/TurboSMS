<?php

namespace edrard\Turbosms;

use edrard\Turbosms\Message;
use GuzzleHttp\Client;

class Api
{
    protected $client;
    protected $config = array();
    protected $url;
    protected $error = array();

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->client = new Client();
    }
    public function changeConfig(array $config){
        $this->config = $config;
    }

    public function sendMessage(Message $message, $url = 'message/send.json')
    {
        $this->error = array();
        $this->url = trim($this->config['url'],'/').'/'.trim($url,'/');
        return [ 'resp' => $this->getResponse($this->prepeQuery($message)), 'error' => $this->error ];
    }
    private function prepeQuery($message)
    {
        $config = $message->getConfig();
        return [
            'recipients' => $config['recip'],
            'sms' => [
                'text' => $config['text'],
                'sender' => $config['from']
            ],
        ];
    }
    private function getResponse(array $body)
    {
        $response = $this->client->request('POST', $this->url, [
            'headers'        => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->config['token'],
            ],
            'json' => $body
        ]);

        $ans = json_decode((string) $response->getBody(), true);

        if(!in_array($ans['response_code'],[801,802,800,803])){
            throw new \Exception($ans['response_code'] ?? 'some erorr in sending sms');
        }
        if (!isset($ans['response_result']) || $ans['response_result'] == array()) {
            throw new \Exception('No response result');
        }
        if(in_array($ans['response_code'], [802,803] )){
            $this->checkResp($ans);
        }

        return $ans;
    }
    private function checkResp($ans){
            foreach($ans['response_result'] as $key => $res){
            if($res['response_code'] != 0){
                $this->error[$key] = $res;
            }
        }

    }
}