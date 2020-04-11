<?php

namespace Cart;

class Fee
{
    public $identifier;
    public $title;
    protected $rawValue;
    protected $value;
    private $isDiscount;
    private $isPercentage;

    public function __construct($identifier, $title, $value) {
        $this->identifier = $identifier;
        $this->title = $title;
        $this->rawValue = (string) $value;
        $this->value = $value;

        $this->isDiscount = $this->rawValue[0] === '-';
        $this->isPercentage = $this->rawValue[-1] === '%';

        if ($this->isPercentage()) {
            $this->value = str_replace('%', '', $this->rawValue);
        }
    }

    public function isPercentage()
    {
        return $this->isPercentage;
    }

    public function isDiscount()
    {
        return $this->isDiscount;
    }

    public function rawValue()
    {
        return $this->rawValue;
    }

    public function value()
    {
        return $this->value;
    }
}