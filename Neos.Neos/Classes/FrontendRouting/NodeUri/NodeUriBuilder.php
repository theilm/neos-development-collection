<?php

/*
 * This file is part of the Neos.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\Neos\FrontendRouting\NodeUri;

use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Helper\UriHelper;
use Neos\Flow\Mvc\Exception\NoMatchingRouteException;
use Neos\Flow\Mvc\Routing\Dto\ResolveContext;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Mvc\Routing\RouterInterface;
use Neos\Neos\FrontendRouting\Projection\DocumentUriPathProjection;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use Psr\Http\Message\UriInterface;

#[Flow\Proxy(false)]
final class NodeUriBuilder
{
    /**
     * Please inject and use the {@see NodeUriBuilderFactory} to acquire this uri builder
     *
     *     #[Flow\Inject]
     *     protected NodeUriBuilderFactory $nodeUriBuilderFactory;
     *
     *     $this->nodeUriBuilderFactory->forRequest($someHttpRequest);
     *
     * @internal must not be manually instantiated but its factory must be used
     */
    public function __construct(
        private readonly RouterInterface $router,
        /**
         * The base uri either set by using Neos.Flow.http.baseUri or inferred from the current request.
         * Note that hard coding the base uri in the settings will not work for multi sites and is only to be used as escape hatch for running Neos in a sub-directory
         */
        private readonly UriInterface $baseUri,
        /**
         * This prefix could be used to append to all uris a prefix via `SCRIPT_NAME`, but this feature is currently not well tested and considered experimental
         */
        private readonly string $uriPathPrefix,
        /**
         * The currently active http attributes that are used to influence the routing. The Neos frontend route part handler requires the {@see SiteDetectionResult} to be serialized in here.
         */
        private readonly RouteParameters $routeParameters
    ) {
    }

    /**
     * Returns a human-readable host relative uri for nodes in the live workspace.
     *
     * As the human-readable uris are only routed for nodes of the live workspace {@see DocumentUriPathProjection}
     * Preview uris are build for other workspaces {@see previewUriFor}
     *
     * Cross-linking nodes
     * -------------------
     *
     * Cross linking to a node happens when the side determined based on the current
     * route parameters (through the host and sites domain) does not belong to the linked node.
     * In this case the domain from the node's site might be used to build a host absolute uri {@see CrossSiteLinkerInterface}.
     *
     * Host relative urls are build by default for non cross-linked nodes.
     *
     * Supported options
     * -----------------
     *
     * forceAbsolute:
     *   Absolute urls for non cross-linked nodes can be enforced via {@see Options::$forceAbsolute}.
     *   In which case the base uri determined by the request is used as host instead of a possibly configured site domain's host.
     *
     * format:
     *   todo
     *
     * routingArguments:
     *   todo
     *
     * Note that appending additional query parameters can be done via {@see UriHelper::uriWithAdditionalQueryParameters()}
     *
     * @api
     * @throws NoMatchingRouteException
     */
    public function uriFor(NodeAddress $nodeAddress, Options $options = null): UriInterface
    {
        if (!$nodeAddress->workspaceName->isLive()) {
            return $this->previewUriFor($nodeAddress, $options);
        }

        $routeValues = $options?->routingArguments ?? [];
        $routeValues['node'] = $nodeAddress;
        $routeValues['@action'] = strtolower('show');
        $routeValues['@controller'] = strtolower('Frontend\Node');
        $routeValues['@package'] = strtolower('Neos.Neos');

        if ($options?->format !== null && $options->format !== '') {
            $routeValues['@format'] = $options->format;
        }

        return $this->router->resolve(
            new ResolveContext(
                $this->baseUri,
                $routeValues,
                $options?->forceAbsolute ?? false,
                $this->uriPathPrefix,
                $this->routeParameters
            )
        );
    }

    /**
     * Returns an uri with json encoded node address as query parameter.
     *
     * Supported options
     * -----------------
     *
     * forceAbsolute:
     *   Absolute urls can be build via {@see Options::$forceAbsolute}, by default host relative urls will be build.
     *
     * Note that other options are not considered for preview uri building.
     *
     * @api
     * @throws NoMatchingRouteException
     */
    public function previewUriFor(NodeAddress $nodeAddress, Options $options = null): UriInterface
    {
        $routeValues = [];
        // todo use withQuery instead
        $routeValues['node'] = $nodeAddress->toJson();
        $routeValues['@action'] = strtolower('preview');
        $routeValues['@controller'] = strtolower('Frontend\Node');
        $routeValues['@package'] = strtolower('Neos.Neos');

        return $this->router->resolve(
            new ResolveContext(
                $this->baseUri,
                $routeValues,
                $options?->forceAbsolute ?? false,
                $this->uriPathPrefix,
                $this->routeParameters
            )
        );
    }
}
