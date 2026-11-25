<?php

namespace edrard\Turbosms;

class Message
{
    protected $from = '';
    protected $text = '';
    protected $recip = array();
    protected $sequenceId;
    protected $channel = 'sms';
    protected $viber = array();
    protected $sms = array();

    public function __construct(array $recip = array(), $from = '', $text = '')
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

    /** Reuse the same ID when retrying the same logical request. Null disables it. */
    public function setSequenceId($sequenceId)
    {
        $this->sequenceId = $sequenceId;
        return $this;
    }

    /** sms (default), viber, or hybrid (Viber first, SMS fallback managed by TurboSMS). */
    public function setChannel($channel)
    {
        $this->channel = $channel;
        return $this;
    }

    /** Viber delivery lifetime in seconds (30..86400). Null uses the API default. */
    public function setViberTtl($seconds)
    {
        return $this->setOption($this->viber, 'ttl', $seconds);
    }

    /** Hybrid message deadline in seconds, not the Viber wait. Null removes the deadline. */
    public function setHybridTtl($seconds)
    {
        return $this->setOption($this->sms, 'hybrid_ttl', $seconds);
    }

    public function setViberFrom($from)
    {
        return $this->setOption($this->viber, 'sender', $from);
    }

    public function setViberText($text)
    {
        return $this->setOption($this->viber, 'text', $text);
    }

    /** Select an externally accessible HTTP(S) image URL. Null removes the URL. */
    public function setViberImageUrl($url)
    {
        if ($url !== null) {
            unset($this->viber['file_id']);
        }
        return $this->setOption($this->viber, 'image_url', $url);
    }

    /** Select an already uploaded image's TurboSMS file ID. Does not upload a file. */
    public function setViberImageFileId($fileId)
    {
        if ($fileId !== null) {
            unset($this->viber['image_url']);
        }
        return $this->setOption($this->viber, 'file_id', $fileId);
    }

    public function setSmsFrom($from)
    {
        return $this->setOption($this->sms, 'sender', $from);
    }

    public function setSmsText($text)
    {
        return $this->setOption($this->sms, 'text', $text);
    }

    private function setOption(array &$options, $key, $value)
    {
        if ($value === null) {
            unset($options[$key]);
        } else {
            $options[$key] = $value;
        }
        return $this;
    }

    public function getConfig()
    {
        $config = array('from' => $this->from, 'text' => $this->text, 'recip' => $this->recip);
        if ($this->sequenceId !== null) {
            $config['sequence_id'] = $this->sequenceId;
        }
        if ($this->channel !== 'sms') {
            $config['channel'] = $this->channel;
        }
        if ($this->viber !== array()) {
            $config['viber'] = $this->viber;
        }
        if ($this->sms !== array()) {
            $config['sms'] = $this->sms;
        }
        return $config;
    }

    /** Effective API channel blocks; the legacy getConfig() shape stays unchanged for SMS. */
    public function getChannels()
    {
        if (!in_array($this->channel, array('sms', 'viber', 'hybrid'), true)) {
            throw new \InvalidArgumentException('Channel must be sms, viber or hybrid.');
        }
        $config = $this->getConfig();
        $defaults = array('text' => $config['text'], 'sender' => $config['from']);
        $channels = array();
        if ($this->channel !== 'sms') {
            $channels['viber'] = array_replace($defaults, $this->viber);
            if ((isset($this->viber['image_url']) || isset($this->viber['file_id'])) && $channels['viber']['text'] === '') {
                unset($channels['viber']['text']);
            }
        }
        if ($this->channel !== 'viber') {
            $channels['sms'] = array_replace($defaults, $this->sms);
        }
        return $channels;
    }

    /** Validate at send time so an empty message can still be built with setters. */
    public function validate()
    {
        $channels = $this->getChannels();
        if ($this->channel === 'sms' && $this->viber !== array()) {
            throw new \InvalidArgumentException('Viber options require the viber or hybrid channel.');
        }
        if ($this->channel === 'viber' && $this->sms !== array()) {
            throw new \InvalidArgumentException('SMS options require the sms or hybrid channel.');
        }
        if (isset($this->sms['hybrid_ttl']) && $this->channel !== 'hybrid') {
            throw new \InvalidArgumentException('hybrid_ttl requires the hybrid channel.');
        }
        if (isset($this->viber['ttl']) && (!is_int($this->viber['ttl']) ||
            $this->viber['ttl'] < 30 || $this->viber['ttl'] > 86400)) {
            throw new \InvalidArgumentException('Viber ttl must be an integer between 30 and 86400 seconds.');
        }
        if (isset($this->sms['hybrid_ttl']) && (!is_int($this->sms['hybrid_ttl']) || $this->sms['hybrid_ttl'] < 1)) {
            throw new \InvalidArgumentException('hybrid_ttl must be a positive integer number of seconds.');
        }
        if (isset($this->viber['image_url'])) {
            $imageUrl = $this->viber['image_url'];
            $parts = is_string($imageUrl) ? parse_url($imageUrl) : false;
            if (!is_string($imageUrl) || preg_match('/[\x00-\x20\x7F]/', $imageUrl) ||
                filter_var($imageUrl, FILTER_VALIDATE_URL) === false || $parts === false ||
                !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), array('http', 'https'), true) ||
                isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
                throw new \InvalidArgumentException('Viber image URL must be an absolute HTTP(S) URL without credentials, fragments or whitespace.');
            }
        }
        if (isset($this->viber['file_id']) && (!is_int($this->viber['file_id']) || $this->viber['file_id'] < 1)) {
            throw new \InvalidArgumentException('Viber image file ID must be a positive integer.');
        }
        foreach ($channels as $name => $options) {
            $this->validateContent($name, $options);
        }
        if (count($this->recip) < 1 || count($this->recip) > 5000) {
            throw new \InvalidArgumentException('Provide between 1 and 5000 recipients.');
        }
        foreach ($this->recip as $recipient) {
            if ((!is_string($recipient) && !is_int($recipient)) ||
                preg_match('/^[1-9][0-9]{6,14}$/D', (string) $recipient) !== 1) {
                throw new \InvalidArgumentException('Recipients must be international phone numbers containing 7 to 15 digits, without a plus sign.');
            }
        }
        if ($this->sequenceId !== null && (!is_string($this->sequenceId) ||
            trim($this->sequenceId) === '' || preg_match('//u', $this->sequenceId) !== 1 ||
            preg_match('/[\x00-\x1F\x7F]/', $this->sequenceId) ||
            preg_match_all('/./us', $this->sequenceId) > 40)) {
            throw new \InvalidArgumentException('sequence_id must be a non-empty UTF-8 string of at most 40 characters without control characters.');
        }
    }

    private function validateContent($channel, array $options)
    {
        $sender = $options['sender'];
        $text = array_key_exists('text', $options) ? $options['text'] : '';
        if (!is_string($sender) || trim($sender) === '' ||
            preg_match('//u', $sender) !== 1 || preg_match('/[\r\n\x00]/', $sender) ||
            preg_match_all('/./us', $sender) > 25) {
            throw new \InvalidArgumentException($channel . ' sender must be valid UTF-8 text of 1 to 25 characters.');
        }
        $limit = $channel === 'viber' ? 1000 : 1521;
        if ($channel === 'viber' && (isset($options['image_url']) || isset($options['file_id'])) && $text === '') {
            return; // An image-only Viber message does not need the optional text parameter.
        }
        if (!is_string($text) || trim($text) === '' || preg_match('//u', $text) !== 1 ||
            strpos($text, "\0") !== false || preg_match_all('/./us', $text) > $limit) {
            throw new \InvalidArgumentException($channel . ' text must be non-empty UTF-8 text of at most ' . $limit . ' characters; network encoding limits also apply.');
        }
    }
}
