</main>
</div>
</div>
<script src="<?= assetUrl('assets/js/script.js') ?>"></script>
<script>
(function () {
    const form = document.getElementById('topAssistantForm');
    const input = document.getElementById('topAssistantInput');
    const panel = document.getElementById('topAssistantPanel');
    const answer = document.getElementById('topAssistantAnswer');
    const endpoint = <?= json_encode(siteUrl('api/controllers/ApiAssistantController.php')) ?>;
    if (!form || !input || !panel || !answer) return;

    async function ask(question) {
        question = String(question || '').trim();
        if (!question) return;
        panel.hidden = false;
        answer.textContent = 'Thinking...';
        input.value = question;
        try {
            const body = new URLSearchParams();
            body.set('question', question);
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body: body.toString()
            });
            const data = await response.json();
            answer.textContent = data.message || 'No response was returned.';
        } catch (e) {
            answer.textContent = 'The HRIS AI Assistant could not connect to the server. Please try again.';
        }
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        ask(input.value);
    });

    document.querySelectorAll('.top-assistant-suggestions button').forEach(function (button) {
        button.addEventListener('click', function () { ask(button.dataset.question); });
    });

    input.addEventListener('focus', function () { panel.hidden = false; });
    document.addEventListener('click', function (e) {
        const wrap = document.getElementById('topbarAssistant');
        if (wrap && !wrap.contains(e.target)) panel.hidden = true;
    });
})();
</script>
</body>
</html>
