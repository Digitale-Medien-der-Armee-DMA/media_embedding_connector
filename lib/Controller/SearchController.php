<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Controller;

use OCA\MediaEmbeddingConnector\AppInfo\Application;
use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCA\MediaEmbeddingConnector\Service\AppAccessPolicy;
use OCA\MediaEmbeddingConnector\Service\AppConfig;
use OCA\MediaEmbeddingConnector\Service\ImageSearchService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class SearchController extends Controller
{
    public function __construct(
        IRequest $request,
        private IUserSession $userSession,
        private ImageSearchService $searchService,
        private AppConfig $config,
        private AppAccessPolicy $accessPolicy,
        private IURLGenerator $urlGenerator,
        private IInitialState $initialState,
        private IEventDispatcher $eventDispatcher,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse|DataResponse
    {
        if (!$this->accessPolicy->isCurrentUserAllowed()) {
            return $this->accessDenied();
        }

        if (class_exists(\OCA\Viewer\Event\LoadViewer::class)) {
            $this->eventDispatcher->dispatchTyped(
                new \OCA\Viewer\Event\LoadViewer(),
            );
        }

        $this->initialState->provideInitialState('search', [
            'search_url' => $this->routeUrl('search'),
            'image_search_url' => $this->routeUrl('image'),
            'similar_url' => $this->routeUrl('similar', ['fileId' => '__FILE_ID__']),
            'indexing_enabled' => $this->config->isIndexingEnabled(),
            'image_accept' => implode(',', array_values(array_diff(
                $this->config->getAllowedImageMimeTypes(),
                $this->config->getDisabledImageMimeTypes(),
            ))),
        ]);

        return new TemplateResponse(Application::APP_ID, 'search/index');
    }

    #[NoAdminRequired]
    public function search(): DataResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['success' => false, 'error' => 'authentication_required'], 401);
        }
        if (!$this->accessPolicy->isUserAllowed($user)) {
            return $this->accessDenied();
        }
        $userId = $user->getUID();

        try {
            return new DataResponse(['success' => true] + $this->searchService->searchText(
                $userId,
                (string)$this->request->getParam('query', ''),
                (int)$this->request->getParam('limit', 48),
                (int)$this->request->getParam('offset', 0),
            ));
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['success' => false, 'error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->searchError($e);
        }
    }

    #[NoAdminRequired]
    public function similar(string $fileId): DataResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['success' => false, 'error' => 'authentication_required'], 401);
        }
        if (!$this->accessPolicy->isUserAllowed($user)) {
            return $this->accessDenied();
        }
        $userId = $user->getUID();

        try {
            return new DataResponse(['success' => true] + $this->searchService->searchSimilar(
                $userId,
                $fileId,
                (int)$this->request->getParam('limit', 48),
                (int)$this->request->getParam('offset', 0),
            ));
        } catch (\Throwable $e) {
            return $this->searchError($e);
        }
    }

    #[NoAdminRequired]
    public function image(): DataResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['success' => false, 'error' => 'authentication_required'], 401);
        }
        if (!$this->accessPolicy->isUserAllowed($user)) {
            return $this->accessDenied();
        }

        $uploadedFile = $this->request->getUploadedFile('image');
        if (!is_array($uploadedFile)) {
            return new DataResponse(['success' => false, 'error' => 'missing_image'], 400);
        }
        $uploadError = (int)($uploadedFile['error'] ?? UPLOAD_ERR_OK);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $code = in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'image_too_large'
                : 'invalid_image_upload';
            return new DataResponse(['success' => false, 'error' => $code], 400);
        }
        if (!is_string($uploadedFile['tmp_name'] ?? null) || $uploadedFile['tmp_name'] === '') {
            return new DataResponse(['success' => false, 'error' => 'invalid_image_upload'], 400);
        }

        $tmpName = $uploadedFile['tmp_name'];
        try {
            return new DataResponse(['success' => true] + $this->searchService->searchUploadedImage(
                $user->getUID(),
                $uploadedFile,
                (int)$this->request->getParam('limit', 48),
                (int)$this->request->getParam('offset', 0),
            ));
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['success' => false, 'error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->searchError($e);
        } finally {
            if (is_uploaded_file($tmpName)) {
                @unlink($tmpName);
            }
        }
    }

    /**
     * Route names are kept in one place so templates do not need a URL generator.
     *
     * @param array<string, string> $parameters
     */
    private function routeUrl(string $method, array $parameters = []): string
    {
        return $this->urlGenerator->linkToRoute(
            Application::APP_ID . '.search.' . $method,
            $parameters,
        );
    }

    private function searchError(\Throwable $e): DataResponse
    {
        $code = $e instanceof ExternalServiceException ? $e->getPublicCode() : 'search_failed';
        $context = [
            'app' => Application::APP_ID,
            'error_code' => $code,
            'exception_class' => $e::class,
        ];
        if (!$e instanceof ExternalServiceException) {
            $context['exception'] = $e;
        }
        $this->logger->warning('Media search failed', $context);
        return new DataResponse(['success' => false, 'error' => $code], 502);
    }

    private function accessDenied(): DataResponse
    {
        return new DataResponse(['success' => false, 'error' => 'access_denied'], 403);
    }
}
