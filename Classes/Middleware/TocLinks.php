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

use Kitodo\Dlf\Domain\Model\TocClickForm;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;

/**
 * Search in document Middleware for plugin 'Search' of the 'dlf' extension
 *
 * @package TYPO3
 * @subpackage dlf
 *
 * @access public
 */
class TocLinks implements MiddlewareInterface
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
            return new Response('Middleware reached no tocClickform', 200);
        }
        return $handler->handle($request);;
    }
}
