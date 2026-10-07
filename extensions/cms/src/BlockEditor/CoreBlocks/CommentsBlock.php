<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;
use Pulsar\Security\Csrf\CsrfTokenManagerInterface;

use function count;
use function htmlspecialchars;
use function is_array;
use function is_bool;
use function is_string;

use const ENT_QUOTES;

#[Internal]
final readonly class CommentsBlock implements BlockTypeInterface
{
    public function __construct(
        private CsrfTokenManagerInterface $csrfTokenManager,
    ) {}

    #[Override]
    public function type(): string
    {
        return 'comments';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'comments' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'author' => ['type' => 'string'],
                            'avatarUrl' => ['type' => 'string', 'format' => 'uri'],
                            'content' => ['type' => 'string'],
                            'date' => ['type' => 'string'],
                        ],
                        'required' => ['id', 'author', 'content', 'date'],
                    ],
                ],
                'contentId' => ['type' => 'string'],
                'action' => ['type' => 'string', 'format' => 'uri'],
                'showForm' => ['type' => 'boolean'],
                'allowReplies' => ['type' => 'boolean'],
            ],
            'required' => ['contentId'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var list<mixed> $comments */
        $comments = $data['comments'] ?? [];
        /** @var mixed $rawShowForm */
        $rawShowForm = $data['showForm'] ?? null;
        $showForm = is_bool($rawShowForm) ? $rawShowForm : true;
        /** @var mixed $rawContentId */
        $rawContentId = $data['contentId'] ?? null;
        $contentId = htmlspecialchars(is_string($rawContentId) ? $rawContentId : '', ENT_QUOTES, 'UTF-8');

        $count = count($comments);
        $html = "<section class=\"comments-block\" id=\"comments\" data-content-id=\"$contentId\">";
        $html .= "<h3 class=\"comments-block__heading\">Comments ($count)</h3>";

        if ($comments !== []) {
            $html .= '<ol class="comments-block__list">';

            foreach ($comments as $comment) {
                if (!is_array($comment)) {
                    continue;
                }

                /** @var array<string, mixed> $comment */
                $html .= $this->renderComment($comment);
            }

            $html .= '</ol>';
        } else {
            $html .= '<p class="comments-block__empty">No comments yet.</p>';
        }

        if ($showForm) {
            $html .= $this->renderForm($data);
        }

        return $html . '</section>';
    }

    /**
     * @param array<string, mixed> $comment
     */
    private function renderComment(array $comment): string
    {
        /** @var mixed $rawAuthor */
        $rawAuthor = $comment['author'] ?? null;
        /** @var mixed $rawContent */
        $rawContent = $comment['content'] ?? null;
        /** @var mixed $rawDate */
        $rawDate = $comment['date'] ?? null;
        $author = htmlspecialchars(is_string($rawAuthor) ? $rawAuthor : '', ENT_QUOTES, 'UTF-8');
        $content = htmlspecialchars(is_string($rawContent) ? $rawContent : '', ENT_QUOTES, 'UTF-8');
        $date = htmlspecialchars(is_string($rawDate) ? $rawDate : '', ENT_QUOTES, 'UTF-8');
        /** @var mixed $avatarUrl */
        $avatarUrl = $comment['avatarUrl'] ?? null;

        $html = '<li class="comments-block__comment">';

        if (is_string($avatarUrl) && $avatarUrl !== '') {
            $escapedAvatar = htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8');
            $html .= "<img src=\"$escapedAvatar\" alt=\"\" class=\"comments-block__avatar\" width=\"40\" height=\"40\" loading=\"lazy\">";
        }

        $html .= '<div class="comments-block__body">'
            . "<strong class=\"comments-block__author\">$author</strong>"
            . "<time class=\"comments-block__date\">$date</time>"
            . "<div class=\"comments-block__content\">$content</div>"
            . '</div>';

        return $html . '</li>';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderForm(array $data): string
    {
        /** @var mixed $rawAction */
        $rawAction = $data['action'] ?? null;
        /** @var mixed $rawContentId */
        $rawContentId = $data['contentId'] ?? null;
        $action = htmlspecialchars(is_string($rawAction) ? $rawAction : '/comments', ENT_QUOTES, 'UTF-8');
        $contentId = htmlspecialchars(is_string($rawContentId) ? $rawContentId : '', ENT_QUOTES, 'UTF-8');
        $csrfToken = htmlspecialchars($this->csrfTokenManager->getToken(), ENT_QUOTES, 'UTF-8');

        return '<form class="comments-block__form" method="post" action="' . $action . '">'
            . "<input type=\"hidden\" name=\"_csrf_token\" value=\"$csrfToken\">"
            . "<input type=\"hidden\" name=\"content_id\" value=\"$contentId\">"
            . '<div class="comments-block__form-field">'
            . '<label for="comment-author">Name</label>'
            . '<input type="text" id="comment-author" name="author" required>'
            . '</div>'
            . '<div class="comments-block__form-field">'
            . '<label for="comment-content">Comment</label>'
            . '<textarea id="comment-content" name="content" required rows="4"></textarea>'
            . '</div>'
            . '<button type="submit" class="comments-block__submit">Post comment</button>'
            . '</form>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['contentId']) || !is_string($data['contentId'])) {
            $errors[] = 'contentId is required and must be a string';
        }

        if (isset($data['comments']) && !is_array($data['comments'])) {
            $errors[] = 'comments must be an array';
        } elseif (isset($data['comments'])) {
            /** @var list<mixed> $comments */
            $comments = $data['comments'];

            foreach ($comments as $idx => $comment) {
                $index = (string) $idx;

                if (!is_array($comment)) {
                    $errors[] = "comments[{$index}] must be an object";

                    continue;
                }

                if (!isset($comment['id']) || !is_string($comment['id'])) {
                    $errors[] = "comments[{$index}].id is required and must be a string";
                }

                if (!isset($comment['author']) || !is_string($comment['author'])) {
                    $errors[] = "comments[{$index}].author is required and must be a string";
                }

                if (!isset($comment['content']) || !is_string($comment['content'])) {
                    $errors[] = "comments[{$index}].content is required and must be a string";
                }

                if (!isset($comment['date']) || !is_string($comment['date'])) {
                    $errors[] = "comments[{$index}].date is required and must be a string";
                }
            }
        }

        if (isset($data['action']) && !is_string($data['action'])) {
            $errors[] = 'action must be a string';
        }

        if (isset($data['showForm']) && !is_bool($data['showForm'])) {
            $errors[] = 'showForm must be a boolean';
        }

        return $errors;
    }
}
