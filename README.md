# TurboSms very simple lib

This package send notificatiob using TurboSMS HTTP API

## Installation

Install this package with Composer:
Requere Php 7.2+

```bash
Add to composer.json in "repositories"
        {
            "type": "git",
            "url": "https://github.com/Edrard/TurboSMS.git"
        }
and to "require"
        {
            "edrard/turbosms": "dev-main",
        }
```

### How to use


```php

...
use edrard\Turbosms\Api;
use edrard\Turbosms\Message;

$config = array(
    'url' => 'https://api.turbosms.ua/',
    'token' => TOKEN,
);



try{
    $msg = new Message();
    $msg->setRecipients(array('380000000000'))->setFrom('Company_name')->setText('Hellow');
    $api = new Api($config);
    $resp = $api->sendMessage($msg);
    if($resp['error'] == array()){
        print_r($resp['resp']);
    }
}Catch(\Exception $e){
    echo $e->getMessage();
}
...
```

## License

The MIT License (MIT).
