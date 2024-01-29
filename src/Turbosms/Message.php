<?php

namespace edrard\Turbosms;


class Message
{
    protected $from = '';
    protected $text = '';
    protected $recip = array();

    public function __construct(array $recip = array(), $from = '',$text = '')
    {
        $this->text = $text;
        $this->from = $from;
        $this->recip = $recip;
    }
    public function setText($text)
    {
        $this->text = $text;
        return $this;
    }

    public function setFrom($from)
    {
        $this->from = $from;
        return $this;
    }

    public function setRecipients(array $recip)
    {
        $this->recip = $recip;
        return $this;
    }
    public function getConfig(){
        return array(
            'from' => $this->from,
            'text' => $this->text,
            'recip' => $this->recip
        );
    }
}
