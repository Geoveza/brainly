<?php

namespace Brainly;

use Exception;

final class Brainly
{
	/**
	 * @var string
	 */
	private $query;

	/**
	 * @var string
	 */
	private $hash;

	/**
	 * @var string
	 */
	private $cacheDir;

	/**
	 * @var string
	 */
	private $cacheFile;

	/**
	 * @var array
	 */
	private $result = [];

	/**
	 * @var bool
	 */
	private $forceNoCache = false;

	/**
	 * @var string
	 */
	private $cookieFile;

	/**
	 * @var string
	 */
	private $out = null;

	/**
	 * @param string $query
	 * @throws \Exception
	 *
	 * Constructor
	 */
	public function __construct($query)
	{
		$this->query = trim(strtolower($query));
		$this->hash = sha1($this->query);

		if (defined("data")) {
			is_dir(data."/brainly") or mkdir(data."/brainly");
			$this->cacheDir = data."/brainly/cache";
			$this->cookieFile = data."/brainly/cookie.txt";
		} else {
			$cwd = getcwd();
			is_dir($cwd."/brainly") or mkdir($cwd."/brainly");
			$this->cacheDir = $cwd."/brainly/cache";
			$this->cookieFile = $cwd."/brainly/cookie.txt";
			unset($cwd);
		}

		$this->cacheFile = $this->cacheDir."/".$this->hash;

		is_dir($this->cacheDir) or mkdir($this->cacheDir);

		if (!is_dir($this->cacheDir)) {
			throw new Exception("Couldn't create cache directory: {$this->cacheDir}");
		}

		if (!is_writeable($this->cacheDir)) {
			throw new Exception("Cache dir is not writeable: {$this->cacheDir}");
		}

		if (file_exists($this->cacheFile)) {

			if (!is_readable($this->cacheFile)) {
				throw new Exception("Cache file does exist but it is not readable");
			}

			if (!is_writeable($this->cacheFile)) {
				throw new Exception("Cache file does exist but it is not writeable");
			}

		}
	}

	/**
	 * @return bool
	 */
	private function lookForCache()
	{
		if ($this->forceNoCache) {
			return false;
		}

		if (file_exists($this->cacheFile)) {
			$this->result = self::parseOut(@gzinflate(file_get_contents($this->cacheFile))."");

			if (!is_array($this->result)) {
				unlink($this->cacheFile);
				$this->result = [];
				return false;
			}

			return true;
		}

		return false;
	}

	/**
	 * @throws \Exception
	 * @return void
	 */
	private function doQuery()
	{
		// Visit the main page first, so that it isn't like a bot.
		// $ch = curl_init("https://brainly.co.id");
		// curl_setopt_array($ch, 
		// 	[
		// 		CURLOPT_RETURNTRANSFER => true,
		// 		CURLOPT_SSL_VERIFYPEER => false,
		// 		CURLOPT_SSL_VERIFYHOST => false,
		// 		CURLOPT_HTTPHEADER => [
		// 			"Accept-Encoding: gzip, deflate, br"
		// 		],
		// 		CURLOPT_USERAGENT => "Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:65.0) Gecko/20100101 Firefox/65.0",
		// 		CURLOPT_COOKIEFILE => $this->cookieFile,
		// 		CURLOPT_COOKIEJAR => $this->cookieFile
		// 	]
		// );
		// curl_exec($ch);
		// $err = curl_error($ch);
		// $ern = curl_errno($ch);
		// curl_close($ch);

		// if ($err) {
		// 	goto curl_error;
		// }

		// Do query.
		$ch = curl_init("https://brainly.co.id/graphql/id?op=SearchQuery");
		curl_setopt_array($ch, 
			[
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_SSL_VERIFYHOST => false,
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => $this->buildQuery(),
				CURLOPT_HTTPHEADER => [
					"Accept-Encoding: gzip, deflate, br",
					"Content-Type: application/json",
					"Origin: https://brainly.co.id"
				],
				CURLOPT_USERAGENT => "Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:65.0) Gecko/20100101 Firefox/65.0",
				CURLOPT_REFERER => "https://brainly.co.id/app/ask?entry=top&q=".urlencode($this->query),
				CURLOPT_COOKIEFILE => $this->cookieFile,
				CURLOPT_COOKIEJAR => $this->cookieFile
			]
		);
		$this->out = gzdecode(curl_exec($ch));
		$err = curl_error($ch);
		$ern = curl_errno($ch);
		curl_close($ch);

		if ($err) {
			goto curl_error;
		}

		$this->result = self::parseOut($this->out);
		return;

curl_error:
		if ($err) {
			throw new Exception("A curl error occured: ({$ern}): {$err}");
		}
	}

	/**
	 * @return array
	 */
	private static function parseOut($out)
	{
		$result = [];
		$out = json_decode($out, true);

		if (!is_array($out)) {
			return [];
		}

		if (isset($out["data"]["questionSearch"]["edges"]) && is_array($out["data"]["questionSearch"]["edges"])) {
			foreach ($out["data"]["questionSearch"]["edges"] as $r) {

				$answers = [];

				if (isset($r["node"]["answers"]["nodes"]) && is_array($r["node"]["answers"]["nodes"])) {
					foreach ($r["node"]["answers"]["nodes"] as $rr) {
						$answers[] = $rr["content"];
					}
				}

				$result[] = [
					"content" => $r["node"]["content"],
					"answers" => $answers
				];
			}
		}

		return $result;
	}

	/**
	 * @return string
	 */
	private function buildQuery()
	{
    	return json_encode(
    		[
	    		"operationName" => "SearchQuery",
	    		"variables" => [
	    			"query" => $this->query,
	    			"after" => null,
	    			"first" => 100
	    		],
	    		"query" => self::GRAPHQL_PAYLOAD
	    	],
	    	JSON_UNESCAPED_SLASHES
	    );
	}

	/**
	 * @return void
	 */
	private function writeCache()
	{
		if (is_string($this->out)) {
			file_put_contents($this->cacheFile, gzdeflate($this->out, 9));
		}
	}

	/**
	 * @return array
	 */
	public function exec()
	{
		if ($this->lookForCache()) {
			return $this->result;
		}

		$this->doQuery();
		$this->writeCache();
		return $this->result;
	}


	private const GRAPHQL_PAYLOAD = <<<'GRAPHQL_PAYLOAD'
query SearchQuery($query: String!, $first: Int!, $after: ID) {
  questionSearch(query: $query, first: $first, after: $after) {
    count
    edges {
      node {
        id
        databaseId
        author {
          id
          databaseId
          isDeleted
          nick
          avatar {
            thumbnailUrl
            __typename
          }
          rank {
            name
            __typename
          }
          __typename
        }
        content
        answers {
          nodes {
            thanksCount
            ratesCount
            rating
            content
            __typename
          }
          hasVerified
          __typename
        }
        __typename
      }
      highlight {
        contentFragments
        __typename
      }
      __typename
    }
    __typename
  }
}
GRAPHQL_PAYLOAD;

}
