<?php

/**
 * (c) Kitodo. Key to digital objects e.V. <contact@kitodo.org>
 *
 * This file is part of the Kitodo and TYPO3 projects.
 *
 * @license GNU General Public License version 3 or later.
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Kitodo\Dlf\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Response;

/**
 * Search in document Middleware for plugin 'Search' of the 'dlf' extension
 *
 * @package TYPO3
 * @subpackage dlf
 *
 * @access public
 */
class NavigationMiddleware implements MiddlewareInterface
{
    /**
     * The process method of the middleware.
     *
     * @access public
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     *
     * @return ResponseInterface 
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
        
            //for TableOfContents Click
            if (isset($body['tocClickForm']))
            {
                $formData = $body['tocClickForm'] ?? null;

                $id     = $formData['id'];
                $page   = $formData['page'];
                $double = $formData['double'] ?? '0';
                $pageUrl = $request->getUri()->getPath();
                $query = http_build_query([
                    'tx_dlf' => [
                    'id'     => $id,
                    'page'   => $page,
                    'double' => $double,
                ]
                ]);
                $uri = $pageUrl . '?' . $query;
                return new RedirectResponse($uri, 303);
            }
            elseif(isset($body['pageSelectForm']))
            {
                $formData = $body['pageSelectForm'] ?? null;

                $id     = $formData['id'];
                $page   = $formData['page'];
                $double = $formData['double'] ?? '0';
                $pageUrl = $request->getUri()->getPath();
                $query = http_build_query([
                    'tx_dlf' => [
                    'id'     => $id,
                    'page'   => $page,
                    'double' => $double,
                ]
                ]);
                $uri = $pageUrl . '?' . $query;
                return new RedirectResponse($uri, 303);
            }
            elseif(isset($body['pageGridForm']))
            {
                $formData = $body['pageGridForm'] ?? null;

                $id     = $formData['id'];
                $page   = $formData['page'];
                $double = $formData['double'] ?? '0';
                $pageUrl = $request->getUri()->getPath();
                $query = http_build_query([
                    'tx_dlf' => [
                    'id'     => $id,
                    'page'   => $page,
                    'double' => $double,
                ]
                ]);
                $uri = $pageUrl . '?' . $query;
                return new RedirectResponse($uri, 303);
            }
            return new Response('Middleware reached no target known', 200);
        }
        return $handler->handle($request);;
    }
}
