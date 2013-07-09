<?php
class Downloader
{
	private static function prepareHandle($url)
	{
		$handle = curl_init();
		curl_setopt($handle, CURLOPT_URL, $url);
		curl_setopt($handle, CURLOPT_HEADER, 1);
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
		curl_setopt($handle, CURLOPT_ENCODING, '');
		return $handle;
	}

	private static function parseResult($result, $url)
	{
		$pos = strpos($result, "\r\n\r\n");
		$headers = [];
		$content = substr($result, $pos + 4);
		$headerLines = explode("\r\n", substr($result, 0, $pos));

		preg_match('/\d{3}/', array_shift($headerLines), $matches);
		$code = intval($matches[0]);

		foreach ($headerLines as $line)
		{
			list($key, $value) = explode(': ', $line);
			if (!isset($headers[$key]))
			{
				$headers[$key] = $value;
			}
			else
			{
				$headers[$key] = array_merge(
					array($headers[$key]),
					array($value));
			}
		}

		//別ハックは、	Another hack
		//私は静かに	makes me
		//泣きます		quietly weep
		$content = '<?xml encoding="utf-8" ?'.'>' . $content;

		return new Document($url, $code, $headers, $content);
	}

	private static function flatten(array $input)
	{
		//map input, that can be multidimensional array, to flat array of urls
		$urls = [];
		array_walk_recursive($input, function($url, $key) use (&$urls)
		{
			$urls[$url] = $url;
		});
		return $urls;
	}

	private static function deflatten(array $documents, array $input)
	{
		$output = [];
		foreach ($input as $key => $value)
		{
			$output[$key] = is_array($value)
				? self::deflatten($documents, $input[$key])
				: $documents[$value];
		}
		return $output;
	}

	public function downloadMulti(array $input)
	{
		$handles = [];
		$documents = [];
		$urls = self::flatten($input);

		//if mirror exists, load its content and purge url from download queue
		$mirrorPaths = [];
		if (Config::$mirrorEnabled)
		{
			foreach ($urls + [] as $url)
			{
				$path = Config::$mirrorPath . DIRECTORY_SEPARATOR . rawurlencode($url) . '.dat';
				$mirrorPaths[$url] = $path;
				if (file_exists($path))
				{
					$rawResult = file_get_contents($path);
					$documents[$url] = self::parseResult($rawResult, $url);
					unset($urls[$url]);
				}
			}
		}

		//prepare curl handles
		$multiHandle = curl_multi_init();
		foreach ($urls as $url)
		{
			$handle = self::prepareHandle($url);
			curl_multi_add_handle($multiHandle, $handle);
			$handles[$url] = $handle;
		}

		//run the query
		$running = null;
		do
		{
			$status = curl_multi_exec($multiHandle, $running);
		}
		while ($status == CURLM_CALL_MULTI_PERFORM);
		while ($running and $status == CURLM_OK)
		{
			if (curl_multi_select($multiHandle) != -1)
			{
				do
				{
					$status = curl_multi_exec($multiHandle, $running);
				}
				while ($status == CURLM_CALL_MULTI_PERFORM);
			}
		}

		//get the documents from curl
		foreach ($handles as $url => $handle)
		{
			$rawResult = curl_multi_getcontent($handle);
			if (Config::$mirrorEnabled)
			{
				file_put_contents($mirrorPaths[$url], $rawResult);
			}
			$documents[$url] = self::parseResult($rawResult, $urls[$url]);
			curl_multi_remove_handle($multiHandle, $handle);
		}

		//close curl handles
		curl_multi_close($multiHandle);

		//convert back to multidimensional array
		return self::deflatten($documents, $input);
	}
}