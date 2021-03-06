<?php

/**
 * This file is part of the Adroit package.
 *
 * (c) bitExpert AG
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace bitExpert\Adroit;

use bitExpert\Adroit\Action\Action;
use bitExpert\Adroit\Action\Resolver\ActionResolver;
use bitExpert\Adroit\Domain\DomainPayloadInterface;
use bitExpert\Adroit\Responder\Resolver\ResponderResolver;
use bitExpert\Adroit\Responder\Responder;
use bitExpert\Adroit\Router\Router;
use bitExpert\Slf4PsrLog\LoggerFactory;
use Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Zend\Stratigility\MiddlewareInterface;

/**
 * MiddlewareInterface implementation for the Adroit framework.
 *
 * @api
 */
class AdroitMiddleware implements MiddlewareInterface
{
    /**
     * @var \Psr\Log\LoggerInterface the logger instance.
     */
    protected $logger;
    /**
     * @var \bitExpert\Adroit\Action\Resolver\ActionResolver[]
     */
    protected $actionResolvers;
    /**
     * @var \bitExpert\Adroit\Responder\Resolver\ResponderResolver[]
     */
    protected $responderResolvers;
    /**
     * @var \bitExpert\Adroit\Router\Router
     */
    protected $router;

    /**
     * Creates a new {@link \bitExpert\Adroit\AdroitMiddleware}.
     *
     * @param \bitExpert\Adroit\Action\Resolver\ActionResolver[] $actionResolvers
     * @param \bitExpert\Adroit\Responder\Resolver\ResponderResolver[] $responderResolvers
     * @param Router $router
     */
    public function __construct(array $actionResolvers, array $responderResolvers, Router $router)
    {
        $this->actionResolvers = $actionResolvers;
        $this->responderResolvers = $responderResolvers;
        $this->router = $router;

        $this->logger = LoggerFactory::getLogger(__CLASS__);
    }


    /**
     * {@inheritdoc}
     * @throws \RuntimeException
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, callable $out = null)
    {
        $this->logger->debug('Handling new request...');

        $request = $this->resolveActionToken($request);
        $action = $this->resolveAction($request);

        // execute the action
        $responseOrPayload = $action->prepareAndExecute($request, $response);

        // if the return value is a response instance, directly return it
        if ($responseOrPayload instanceof ResponseInterface) {
            $this->logger->debug('Received response. Returning directly.');
            return $responseOrPayload;
        }

        // if the return value is a ModelAndResponder instance, resolve the
        // responder and
        if ($responseOrPayload instanceof DomainPayloadInterface) {
            $this->logger->debug('Received domain payload. Need a responder...');
            $responder = $this->resolveResponder($request, $responseOrPayload);
            return $responder->buildResponse($responseOrPayload, $response);
        }

        $message = sprintf(
            'Action "%s" returned wrong type. Expected \Psr\Http\Message\ResponseInterface or ' .
            '\bitExpert\Adroit\Domain\DomainPayload but got %s',
            get_class($action),
            is_object($responseOrPayload) ? get_class($responseOrPayload) : gettype($responseOrPayload)
        );

        $this->logger->error($message);
        $response->getBody()->rewind();
        $response->getBody()->write($message);
        $response->getBody()->rewind();
        $response = $response->withStatus(500);
        return $response->withHeader('Content-Type', 'application/html');
    }

    /**
     * Resolves the actionToken from the given $request.
     *
     * @param ServerRequestInterface $request
     * @return ServerRequestInterface
     */
    protected function resolveActionToken(ServerRequestInterface $request)
    {
        return $this->router->resolveActionToken($request);
    }

    /**
     * Tries to resolve an {@link \bitExpert\Adroit\Action\Action} instance by querying
     * the $actionResolvers. In case no matching action instance could be found an
     * exception gets thrown.
     *
     * @param ServerRequestInterface $request
     * @return Action
     * @throws RuntimeException
     */
    protected function resolveAction(ServerRequestInterface $request)
    {
        $this->logger->debug('Trying to resolve action...');

        foreach ($this->actionResolvers as $index => $resolver) {
            if (!$resolver instanceof ActionResolver) {
                $this->logger->warning(sprintf(
                    'Action resolver at index %s is an instance of class %s which does not implement ' .
                    '\bitExpert\Adroit\Action\Resolver\ActionResolver. Skipped.',
                    $index,
                    get_class($resolver)
                ));
                continue;
            }

            $action = $resolver->resolve($request);
            if ($action instanceof Action) {
                // step out of the loop when an action could be found
                // by the resolver. First resolver wins!
                $this->logger->debug(sprintf(
                    'Resolved action %s via resolver %s',
                    get_class($action),
                    get_class($resolver)
                ));
                return $action;
            }
        }

        $message = 'An action could not be resolved from the given request!';
        $this->logger->error($message);
        throw new RuntimeException($message);
    }

    /**
     * Tries to resolve an {@link \bitExpert\Adroit\Responder\Responder} instance by querying
     * the $responderResolvers. In case no matching action instance could be found an
     * exception gets thrown.
     *
     * @param ServerRequestInterface $request
     * @param DomainPayloadInterface $domainPayload
     * @return Responder
     * @throws RuntimeException
     */
    protected function resolveResponder(ServerRequestInterface $request, DomainPayloadInterface $domainPayload)
    {
        $this->logger->debug(sprintf(
            'Trying to resolve responder for domainpayload of type %s...',
            $domainPayload->getType()
        ));

        foreach ($this->responderResolvers as $index => $resolver) {
            if (!$resolver instanceof ResponderResolver) {
                $this->logger->warning(sprintf(
                    'Action resolver at index %s is an instance of class %s which does not implement ' .
                    '\bitExpert\Adroit\Action\Resolver\ActionResolver. Skipped.',
                    $index,
                    get_class($resolver)
                ));
                continue;
            }

            $responder = $resolver->resolve($request, $domainPayload);
            if ($responder instanceof Responder) {
                // step out of the loop when a responder could be found
                // by the resolver. First resolver wins!
                $this->logger->debug(sprintf(
                    'Resolved responder %s via resolver %s',
                    get_class($responder),
                    get_class($resolver)
                ));
                return $responder;
            }
        }

        throw new RuntimeException(sprintf(
            'A responder for domainpayload of type "%s" could not be found! Check your configuration!',
            $domainPayload->getType()
        ));
    }
}
