<?php

/* Copyright: Bickel Advisory Services, LLC. */

/** Load Testing Page Response */
class LoadTestingPageResponse
{
	/** Request information */
	private $info = array();
	
	/** Content */
	private $content = null;
	
	/** DOMDocument of content */
	private $dom = null;
	
	/**
	 * Set the curl info for the page
	 * @param string Curl info
	 */
	public function setInfo($info)
	{
		$this->info = $info;
		
		// Output timing info
		echo $this->getCurrentUrl() . ': ' . $this->getTotalTime() . 's' . PHP_EOL;
	}
	
	/**
	 * Get the curl info for the page
	 * @return string Curl info
	 */
	public function getInfo()
	{
		return $this->info;
	}
	
	/**
	 * Set the page content.
	 * @param string Page content
	 */
	public function setContent($content)
	{
		$this->content = $content;
		$this->doc = new DOMDocument();
		@$this->doc->loadHTML($this->content);
	}
	
	/**
	 * Get the page content.
	 * @return string Page content
	 */
	public function getContent()
	{
		return $this->content;
	}
	
	/**
	 * Get HTTP status code
	 * @return int HTTP status code
	 */
	public function getHttpStatus()
	{
		return !empty($this->info['http_code']) ? (int)$this->info['http_code'] : null;
	}
	
	/**
	 * Check if there was an error
	 * @return bool True if this looks like there was an error
	 */
	public function hasError()
	{
		if ($this->getHttpStatus() != 200)
			return true;
		
		// Application specific code here
		// Check for 'err' URL parameter
		parse_str(parse_url($this->info['url'], PHP_URL_QUERY), $params);
		return isset($params['err']);
	}
	
	/**
	 * Get error message returned to user
	 * @return string Error message returned to the user
	 */
	public function getUserErrorMessage()
	{
		// Add application specific code here to get an application specific error message
		
		return null;
	}
	
	/**
	 * Check if the page appears as though a POST form submission succeeded
	 * @return bool True if this looks like a POST form successful submission
	 */
	public function isPlausibleSuccessfulPost()
	{
		// Application specific code here
		return !$this->hasError() && @(int)$this->info['redirect_count'] > 0;
	}
	
	/**
	 * Get current URL
	 * @return string Current URL
	 */
	public function getCurrentUrl()
	{
		return !empty($this->info['url']) ? $this->info['url'] : null;
	}
	
	/**
	 * Get total time to load page
	 * @return float Total time to load page
	 */
	public function getTotalTime()
	{
		return !empty($this->info['total_time']) ? (float)$this->info['total_time'] : 0.0;
	}
	
	/**
	 * Parse the HTML content.
	 * @return DOMDocument
	 */
	public function getHtmlDoc()
	{
		return $this->doc;
	}
	
	/**
	 * Get base URL
	 * @return string Base URL
	 */
	public function getRelativeBase()
	{
		$base = '';
		$baseElem = $this->doc->getElementsByTagName('base');
		if ($baseElem->length > 0)
			$base = $baseElem->item(0)->getAttribute('href');
		else
		{
			if (($pos = strrpos($this->info['url'], '/')))
				$base = substr($this->info['url'], 0, $pos);
		}
		if (isset($base[0]) && $base[strlen($base)-1] == '/')
			$base = substr($base, 0, strlen($base) - 1);
		return $base;
	}
	
	/**
	 * Get base host
	 * @return string Base URL
	 */
	public function getAbsoluteBase()
	{
		$base = '';
		$baseElem = $this->doc->getElementsByTagName('base');
		if ($baseElem->length > 0)
			$base = $baseElem->item(0)->getAttribute('href');
		else
		{
			if (preg_match('|^(https?://[^/]+)(:?/.*)?$|AD', $this->info['url'], $match))
				$base = $match[1];
		}
		if (isset($base[0]) && $base[strlen($base)-1] == '/')
			$base = substr($base, 0, strlen($base) - 1);
		return $base;
	}
	
	/**
	 * Get list of links on the page.
	 * @return array List of unique links
	 */
	public function getLinks()
	{
		$absBase = $this->getAbsoluteBase();
		$relBase = $this->getRelativeBase();
		
		$links = array();
		$linkElems = $this->doc->getElementsByTagName('a');
		foreach ($linkElems as $linkElem)
		{
			$link = trim($linkElem->getAttribute('href'));
			if (!empty($link))
			{
				if (preg_match('|^[^/]*//|AD', $link))
				{}
				else if ($link[0] == '?' || $link[0] == '#')
				{
					$tmp = $this->getCurrentUrl();
					if (($pos = strpos($tmp, $link[0])) !== false)
						$tmp = substr($tmp, 0, $pos);
					$link = $tmp . $link;
				}
				else if ($link[0] == '/')
					$link = $absBase . $link;
				else
					$link = $relBase . '/' . $link;
				
				if (!in_array($link, $links))
					$links[] = $link;
			}
		}
		return $links;
	}
	
	/**
	 * Get form elements.
	 * @return array Array of form elements
	 */
	public function getFormElems()
	{
		// Find the first non-login form
		$forms = $this->doc->getElementsByTagName('form');
		$form = null;
		foreach ($forms as $tmp)
		{
			if (!preg_match('/login/AD', $tmp->getAttribute('action')))
			{
				$form = $tmp;
				break;
			}
		}
			
		$elems = array();
		$tmp1 = $this->doc->getElementsByTagName('input');
		foreach ($tmp1 as $tmp2)
			$elems[] = $tmp2;
		$tmp1 = $this->doc->getElementsByTagName('textarea');
		foreach ($tmp1 as $tmp2)
			$elems[] = $tmp2;
		$tmp1 = $this->doc->getElementsByTagName('select');
		foreach ($tmp1 as $tmp2)
			$elems[] = $tmp2;
		$tmp1 = $this->doc->getElementsByTagName('button');
		foreach ($tmp1 as $tmp2)
			$elems[] = $tmp2;
		return $elems;
	}
	
	/**
	 * Get form elements names.
	 * @return array Array of form element names.
	 */
	public function getFormElemNames()
	{
		$names = array();
		foreach ($this->getFormElems() as $elem)
		{
			$name = $elem->getAttribute('name');
			if (!empty($name))
				$names[] = $name;
		}
		return $names;
	}
	
	/**
	 * Get submit button texts
	 * @return array Array of submit button texts
	 */
	public function getSubmitButtonTexts()
	{
		$rtn = array();
		$tmp1 = $this->doc->getElementsByTagName('input');
		foreach ($tmp1 as $tmp2)
			if (strtolower($tmp2->getAttribute('type')) == 'submit')
				$rtn[] = $tmp2->getAttribute('value');
		return $rtn;
	}
	
	/**
	 * Randomly select an option from a select box
	 * @param DomElement $elem Select box
	 * @return string Value
	 */
	public function selectDropdownValue(DomElement $elem)
	{
		return $elem->childNodes->item(rand(0, $elem->childNodes->length-1))->getAttribute('value');
	}
	
	/**
	 * Get list of css hrefs on the page.
	 * @return array List of unique css hrefs
	 */
	public function getCssHrefs()
	{
		$absBase = $this->getAbsoluteBase();
		$relBase = $this->getRelativeBase();
		
		$hrefs = array();
		$linkElems = $this->doc->getElementsByTagName('link');
		foreach ($linkElems as $linkElem)
		{
			// Check if this is a stylesheet
			if ($linkElem->getAttribute('rel') == 'stylesheet')
			{
				$href = trim($linkElem->getAttribute('href'));
				if (!empty($href))
				{
					if (preg_match('|^[^/]*//|AD', $href))
					{}
					else if ($href[0] == '?' || $href[0] == '#')
					{
						$tmp = $this->getCurrentUrl();
						if (($pos = strpos($tmp, $href[0])) !== false)
							$tmp = substr($tmp, 0, $pos);
						$href = $tmp . $href;
					}
					else if ($href[0] == '/')
						$href = $absBase . $href;
					else
						$href = $relBase . '/' . $href;
					
					if (!in_array($href, $hrefs))
						$hrefs[] = $href;
				}
			}
		}
		return $hrefs;
	}
	
	/**
	 * Get list of image href on the page.
	 * @return array List of unique image hrefs
	 */
	public function getImageHrefs()
	{
		$absBase = $this->getAbsoluteBase();
		$relBase = $this->getRelativeBase();
		
		$hrefs = array();
		$imgElems = $this->doc->getElementsByTagName('img');
		foreach ($imgElems as $imgElem)
		{
			$href = trim($imgElem->getAttribute('href'));
			if (!empty($href))
			{
				if (preg_match('|^[^/]*//|AD', $href))
				{}
				else if ($href[0] == '?' || $href[0] == '#')
				{
					$tmp = $this->getCurrentUrl();
					if (($pos = strpos($tmp, $href[0])) !== false)
						$tmp = substr($tmp, 0, $pos);
					$href = $tmp . $href;
				}
				else if ($href[0] == '/')
					$href = $absBase . $href;
				else
					$href = $relBase . '/' . $href;
				
				if (!in_array($href, $hrefs))
					$hrefs[] = $href;
			}
		}
		return $hrefs;
	}
	
	/**
	 * Get list of javascript srcs on the page.
	 * @return array List of unique javascript srcs
	 */
	public function getJavascriptSrcs()
	{
		$absBase = $this->getAbsoluteBase();
		$relBase = $this->getRelativeBase();
		
		$srcs = array();
		$scriptElems = $this->doc->getElementsByTagName('script');
		foreach ($scriptElems as $scriptElem)
		{
			$src = trim($scriptElem->getAttribute('src'));
			if (!empty($src))
			{
				if (preg_match('|^[^/]*//|AD', $src))
				{}
				else if ($src[0] == '?' || $src[0] == '#')
				{
					$tmp = $this->getCurrentUrl();
					if (($pos = strpos($tmp, $src[0])) !== false)
						$tmp = substr($tmp, 0, $pos);
					$src = $tmp . $src;
				}
				else if ($src[0] == '/')
					$src = $absBase . $src;
				else
					$src = $relBase . '/' . $src;
				
				if (!in_array($src, $srcs))
					$srcs[] = $src;
			}
		}
		return $srcs;
	}
}

?>