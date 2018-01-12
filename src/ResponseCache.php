<?php

namespace Spatie\ResponseCache;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Spatie\ResponseCache\CacheProfiles\CacheProfile;

use Illuminate\Support\Facades\Session;

class ResponseCache
{
    /** @var ResponseCache */
    protected $cache;

    /** @var RequestHasher */
    protected $hasher;

    /** @var CacheProfile */
    protected $cacheProfile;

    public function __construct(ResponseCacheRepository $cache, RequestHasher $hasher, CacheProfile $cacheProfile)
    {
        $this->cache = $cache;
        $this->hasher = $hasher;
        $this->cacheProfile = $cacheProfile;
    }

    public function enabled(Request $request): bool
    {
        return $this->cacheProfile->enabled($request);
    }

    public function shouldCache(Request $request, Response $response): bool
    {
        if ($request->attributes->has('responsecache.doNotCache')) {
            return false;
        }

        if (! $this->cacheProfile->shouldCacheRequest($request)) {
            return false;
        }

        return $this->cacheProfile->shouldCacheResponse($response);
    }

    public function cacheResponse(Request $request, Response $response, $lifetimeInMinutes = null): Response
    {
        if (config('responsecache.add_cache_time_header')) {
            $response = $this->addCachedHeader($response);
        }

        $this->cache->put(
            $this->hasher->getHashFor($request),
            $response,
            ($lifetimeInMinutes) ? intval($lifetimeInMinutes) : $this->cacheProfile->cacheRequestUntil($request)
        );

        return $response;
    }

    public function hasBeenCached(Request $request): bool
    {
        return config('responsecache.enabled')
            ? $this->cache->has($this->hasher->getHashFor($request))
            : false;
    }

    public function getCachedResponseFor(Request $request): Response
	{

		$response = $this->cache->get($this->hasher->getHashFor($request));
		
		$cachedContent = $response->getContent();
		$newContent = $this->updateCsrfToken($cachedContent);
		$response->setContent($newContent);

		return $response;
	}

	public function updateCsrfToken($responseContent)
	{
		$tokenFound = false;

		// try to find input field
		$findInputToken   = 'name="_token"';
		$posInputToken = strpos($responseContent, $findInputToken);

		if ($posInputToken !== false) {

			$inputPartial = substr($responseContent, 0, $posInputToken);

			// go back and find tag start
			$findInputTag   = '<input';
			$posInputTag = strrpos($inputPartial, $findInputTag);

			if ($posInputTag !== false) {

				$offsetInputTag = $posInputTag + strlen($findInputTag);

				// find tag value start
				$findInputValue = 'value="';
				$posInputValue = strpos($responseContent, $findInputValue, $offsetInputTag);

				if ($posInputValue !== false) {
					$offsetInputValue = $posInputValue + strlen($findInputValue);

					// find tag value end
					$findInputQuotes = '"';
					$posInputQuotes = strpos($responseContent, $findInputQuotes, $offsetInputValue);

					if ($posInputQuotes !== false) {

						$tokenFound = true;

						$offsetInputQuotes = $posInputQuotes + strlen($findInputQuotes);
						$oldCsrf = substr($responseContent, $offsetInputValue, $offsetInputQuotes - $offsetInputValue - 1);

						// get session token
						$newCsrf = Session::token();

						// replace token
						$newContent = str_replace($oldCsrf, $newCsrf, $responseContent);

					}
				}
			}
		}

		if($tokenFound === false) {

			// try to find meta field
			$findMetaName   = 'name="csrf-token"';
			$posMetaName = strpos($responseContent, $findMetaName);

			if ($posMetaName !== false) {

				$metaPartial = substr($responseContent, 0, $posMetaName);

				// go back and find tag start
				$findMetaTag   = '<meta';
				$posMetaTag = strrpos($metaPartial, $findMetaTag);

				if ($posMetaTag !== false) {

					$offsetMetaTag = $posMetaTag + strlen($findMetaTag);

					// find tag content start
					$findMetaToken = 'content="';
					$posMetaToken = strpos($responseContent, $findMetaToken, $offsetMetaTag);

					if ($posMetaToken !== false) {

						$offsetMetaToken = $posMetaToken + strlen($findMetaToken);

						// find tag content end
						$findMetaQuotes = '"';
						$posMetaQuotes = strpos($responseContent, $findMetaQuotes, $offsetMetaToken);

						if ($posMetaQuotes !== false) {

							$tokenFound = true;

							$offsetMetaQuotes = $posMetaQuotes + strlen($findMetaQuotes);
							$oldCsrf = substr($responseContent, $offsetMetaToken, $offsetMetaQuotes - $offsetMetaToken - 1);

							// get session token
							$newCsrf = Session::token();

							// replace token
							$newContent = str_replace($oldCsrf, $newCsrf, $responseContent);

						}
					}
				}
			}
		}

		if($tokenFound === true) {
			return $newContent;
		} else {
			return $responseContent;
		}

	}

    public function flush()
    {
        $this->cache->flush();
    }

    protected function addCachedHeader(Response $response): Response
    {
        $clonedResponse = clone $response;

        $clonedResponse->headers->set('laravel-responsecache', 'cached on '.date('Y-m-d H:i:s'));

        return $clonedResponse;
    }
}
