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

			$offsetInputToken = $posInputToken + strlen($findInputToken);

			$findInputValue = 'value="';
			$posInputValue = strpos($responseContent, $findInputValue, $offsetInputToken);

			if ($posInputValue !== false) {
				$offsetInputValue = $posInputValue + strlen($findInputValue);

				$findInputQuotes = '"';
				$posInputQuotes = strpos($responseContent, $findInputQuotes, $offsetInputValue);

				if ($posInputQuotes !== false) {

					$tokenFound = true;

					$offsetInputQuotes = $posInputQuotes + strlen($findInputQuotes);
					$oldCsrf = substr($responseContent, $offsetInputValue, $offsetInputQuotes - $offsetInputValue - 1);

					$newCsrf = Session::token();
					$newContent = str_replace($oldCsrf, $newCsrf, $responseContent);

				}
			}

		}

		if($tokenFound === false) {

			// find meta field
			$findMetaName   = 'name="csrf-token"';
			$posMetaName = strpos($responseContent, $findMetaName);

			if ($posMetaName !== false) {

				$offsetMetaName = $posMetaName + strlen($findMetaName);

				$findMetaToken = 'content="';
				$posMetaToken = strpos($responseContent, $findMetaToken, $offsetMetaName);

				if ($posMetaToken !== false) {
					$offsetMetaToken = $posMetaToken + strlen($findMetaToken);

					$findMetaQuotes = '"';
					$posMetaQuotes = strpos($responseContent, $findMetaQuotes, $offsetMetaToken);

					if ($posMetaQuotes !== false) {

						$tokenFound = true;

						$offsetMetaQuotes = $posMetaQuotes + strlen($findMetaQuotes);
						$oldCsrf = substr($responseContent, $offsetMetaToken, $offsetMetaQuotes - $offsetMetaToken - 1);
						$newCsrf = Session::token();
						$newContent = str_replace($oldCsrf, $newCsrf, $responseContent);

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
