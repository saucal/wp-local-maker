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
		$file = $this->getFilename();
		foreach( $this->exclude as $pattern ) {
			$pattern = str_replace(".", '\.', $pattern);
			$pattern = str_replace("*", ".*?", $pattern);
			$match = preg_match('/^'. $pattern .'$/i', $file, $matches);
			if( ! empty( $matches ) ) {
				return false; // is excluded
			}
		}
        return true;
    }
    public function getChildren()
    {
        return new WP_LMaker_Dir_Filter($this->getInnerIterator()->getChildren(), $this->exclude);
    }
}