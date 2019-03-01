<?php
class WP_LMaker_Dir_Filter extends RecursiveFilterIterator
{
    protected $exclude;
    public function __construct($iterator, array $exclude)
    {
        parent::__construct($iterator);
        $this->exclude = $exclude;
    }
    public function accept()
    {
        return !(in_array($this->getFilename(), $this->exclude));
    }
    public function getChildren()
    {
        return new WP_LMaker_Dir_Filter($this->getInnerIterator()->getChildren(), $this->exclude);
    }
}