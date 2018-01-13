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

		if (config('responsecache.replace_csrf')) {
			$cachedContent = $response->getContent();
			$newContent = $this->updateCsrfToken($cachedContent);
			$response->setContent($newContent);
		}

		return $response;
	}

	public function updateCsrfToken($responseContent)
	{
		// try to find input field
		$content = $this->findAttributeInTag($responseContent, 'input', 'name="_token"', 'value');
		if($content) {
			return $content;
		} else {
			// try to find meta field
			$content = $this->findAttributeInTag($responseContent, 'meta', 'name="csrf-token"', 'content');
			if($content) {
				return $content;
			} else {
				return $responseContent;
			}
		}
	}

	public function findAttributeInTag($content, $tag, $key, $attribute)
	{

		// try to find key
		$posKey = strpos($content, $key);

		if ($posKey !== false) {

			$partial = substr($content, 0, $posKey);

			// go back and find tag start
			$tagStart = '<'.$tag;
			$posTagStart = strrpos($partial, $tagStart);

			if ($posTagStart !== false) {

				$offsetTag = $posTagStart + strlen($tagStart);

				// find attribute start
				$attributeStart = $attribute . '="';
				$posAttStart = strpos($content, $attributeStart, $offsetTag);

				if ($posAttStart !== false) {

					$offsetAttStart = $posAttStart + strlen($attributeStart);

					// find attribute end
					$attributeEnd = '"';
					$posAttEnd = strpos($content, $attributeEnd, $offsetAttStart);

					if ($posAttEnd !== false) {

						$offsetAttEnd = $posAttEnd + strlen($attributeEnd);

						$oldCsrf = substr($content, $offsetAttStart, $offsetAttEnd - $offsetAttStart - 1);

						// get session token
						$newCsrf = Session::token();

						// replace token
						$newContent = str_replace($oldCsrf, $newCsrf, $content);

						return $newContent;

					} else {
						return false;
					}
				} else {
					return false;
				}
			} else {
				return false;
			}
		} else {
			return false;
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
