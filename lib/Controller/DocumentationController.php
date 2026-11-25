<?php

declare(strict_types=1);

namespace OCA\INValidator\Controller;

use OCA\INValidator\Service\TextEditorLoader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

class DocumentationController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private TextEditorLoader $textEditorLoader,
        private IURLGenerator $urls
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(string $document = 'readme'): TemplateResponse
    {
        $this->textEditorLoader->load();
        return new TemplateResponse('inv_validator', 'readme', [
            'document' => in_array($document, ['admin', 'user', 'developer'], true) ? $document : 'readme',
            'readmeUrl' => $this->urls->linkToRoute('inv_validator.documentation.index'),
        ], TemplateResponse::RENDER_AS_BASE);
    }
}
