<?php
use Pulsar\Integrity\FileVerificationStatus;
use Pulsar\Integrity\VerificationResult;

assert($result instanceof VerificationResult);

$totalFiles = count($result->files);
?>
<section aria-labelledby="integrity-heading">
    <h2 id="integrity-heading" class="status-section-title">File Integrity Verification</h2>

    <div class="integrity-summary" role="group" aria-label="Integrity statistics">
        <div class="integrity-summary__stat">
            <span class="integrity-summary__value"><?= $totalFiles ?></span>
            <span class="integrity-summary__label">Total Files</span>
        </div>
        <div class="integrity-summary__stat">
            <span class="integrity-summary__value"><?= $result->verified ?></span>
            <span class="integrity-summary__label">Verified</span>
        </div>
        <div class="integrity-summary__stat">
            <span class="integrity-summary__value"><?= $result->modified ?></span>
            <span class="integrity-summary__label">Modified</span>
        </div>
        <div class="integrity-summary__stat">
            <span class="integrity-summary__value"><?= $result->missing ?></span>
            <span class="integrity-summary__label">Missing</span>
        </div>
    </div>

<?php if ($result->files !== []): ?>
    <div class="status-banner status-banner--<?= $result->passed ? 'healthy' : 'unhealthy' ?>" role="status">
        <span class="status-banner__icon" aria-hidden="true"></span>
        <span><?= $result->passed ? 'All files verified' : 'Integrity violations detected' ?></span>
    </div>

    <table class="integrity-table" aria-label="File integrity details">
        <thead>
            <tr>
                <th scope="col">File Path</th>
                <th scope="col">Expected Hash</th>
                <th scope="col">Actual Hash</th>
                <th scope="col">Status</th>
            </tr>
        </thead>
        <tbody>
<?php foreach ($result->files as $file): ?>
<?php
    $statusCss = match ($file->status) {
        FileVerificationStatus::Verified => 'verified',
        FileVerificationStatus::Modified => 'modified',
        FileVerificationStatus::Missing => 'missing',
        FileVerificationStatus::Added => 'modified',
    };
    $statusLabel = match ($file->status) {
        FileVerificationStatus::Verified => 'OK',
        FileVerificationStatus::Modified => 'Modified',
        FileVerificationStatus::Missing => 'Missing',
        FileVerificationStatus::Added => 'Added',
    };
    ?>
            <tr>
                <td class="integrity-table__path"><?= htmlspecialchars($file->path, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></td>
                <td class="integrity-table__hash" title="<?= htmlspecialchars($file->expectedHash ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"><?= htmlspecialchars($file->expectedHash !== null ? substr($file->expectedHash, 0, 16) . '...' : '-', ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></td>
                <td class="integrity-table__hash" title="<?= htmlspecialchars($file->actualHash ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"><?= htmlspecialchars($file->actualHash !== null ? substr($file->actualHash, 0, 16) . '...' : '-', ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></td>
                <td class="integrity-table__status--<?= $statusCss ?>"><?= $statusLabel ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p class="empty-state">No files in the integrity manifest.</p>
<?php endif; ?>

    <div class="integrity-actions">
        <span class="integrity-actions__btn" aria-disabled="true">
            Run repair via CLI: <code>bin/pulsar integrity:repair</code>
        </span>
    </div>
</section>
