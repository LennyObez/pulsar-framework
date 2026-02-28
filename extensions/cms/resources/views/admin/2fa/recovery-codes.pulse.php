@extends('admin.layout')

@section('title', 'Recovery Codes')

@section('content')
<div class="cms-2fa-recovery">
    <header class="cms-2fa-recovery__header">
        <h1 class="cms-2fa-recovery__title">Recovery Codes</h1>
    </header>

    <div class="cms-alert cms-alert--warning" role="alert">
        <p><strong>Save these recovery codes in a safe place.</strong> They will only be shown once. Each code can be used exactly once to sign in if you lose access to your authenticator app.</p>
    </div>

    <div class="cms-2fa-recovery__codes" id="cms-recovery-codes">
        <div class="cms-2fa-recovery__grid">
            @foreach ($recoveryCodes ?? [] as $code)
                <code class="cms-2fa-recovery__code">{{ $code }}</code>
            @endforeach
        </div>
    </div>

    <div class="cms-2fa-recovery__actions">
        <button type="button"
                class="cms-btn cms-btn--outline"
                id="cms-copy-recovery-codes"
                aria-label="Copy all recovery codes to clipboard">
            Copy All
        </button>
        <button type="button"
                class="cms-btn cms-btn--outline"
                id="cms-download-recovery-codes"
                aria-label="Download recovery codes as a text file">
            Download as Text
        </button>
    </div>

    <form method="POST" action="/admin/cms/2fa/recovery-codes/confirm" class="cms-2fa-recovery__confirm">
        @csrf
        <button type="submit" class="cms-btn cms-btn--primary">I've saved my codes</button>
    </form>
</div>

<script>
(function () {
    var codes = [];
    var codeEls = document.querySelectorAll('.cms-2fa-recovery__code');
    for (var i = 0; i < codeEls.length; i++) {
        codes.push(codeEls[i].textContent.trim());
    }
    var codesText = codes.join('\n');

    // Copy all
    var copyBtn = document.getElementById('cms-copy-recovery-codes');
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(codesText).then(function () {
                    var original = copyBtn.textContent;
                    copyBtn.textContent = 'Copied';
                    setTimeout(function () { copyBtn.textContent = original; }, 2000);
                });
            }
        });
    }

    // Download as text
    var downloadBtn = document.getElementById('cms-download-recovery-codes');
    if (downloadBtn) {
        downloadBtn.addEventListener('click', function () {
            var blob = new Blob([codesText], { type: 'text/plain' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'recovery-codes.txt';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        });
    }
})();
</script>
@endsection
