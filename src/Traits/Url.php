<?php

declare(strict_types=1);

namespace SlimCMS\Traits;

trait Url
{
    protected function url(string $url = '', string $path = ''): string
    {
        $uri = $this->request->getUri();
        if (empty($url) || preg_match('/^&/', $url)) {
            $query = $uri->getQuery() . $url;
        } elseif (strpos($url, '?') !== false) {
            list($path, $query) = explode('?', $url);
        }
        !empty($query) && parse_str($query, $output);
        $query = !empty($output) ? http_build_query($output) : '';
        if (empty($path)) {
            $path = $uri->getPath();
        }
        return $path . ($query ? '?' . $query : '');
    }
}
