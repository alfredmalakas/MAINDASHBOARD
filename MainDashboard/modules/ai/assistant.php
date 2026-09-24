<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';
requireLogin();

$pageTitle = 'AI HRIS Assistant';
include __DIR__ . '/../../includes/header.php';
?>

<div class="ai-page">
    <div class="card ai-hero">
        <div class="ai-hero-icon">✦</div>
        <div>
            <h2>AI HRIS Assistant</h2>
            <p>Ask how to use the HRIS, understand the process, or get role-appropriate information. The assistant replies in the same language you use.</p>
        </div>
    </div>

    <div class="card ai-chat-card">
        <div class="ai-chat-messages" id="aiMessages">
            <div class="ai-message ai-bot">
                <div class="ai-avatar">AI</div>
                <div class="ai-bubble">
                    Hello! I can guide you through the HRIS process and modules based on your role: <strong>Employee, HR Administrator, or Super Admin</strong>. Ask in Tagalog and I will answer in Tagalog; ask in English and I will answer in English.
                </div>
            </div>
        </div>

        <div class="ai-suggestions">
            <button type="button" data-question="What is my attendance status?">My attendance</button>
            <button type="button" data-question="What is my leave balance?">My leave balance</button>
            <button type="button" data-question="Show my latest payroll">My payroll</button>
            <button type="button" data-question="How do I use the HRIS system?">How to use HRIS</button>
            <button type="button" data-question="What is my performance rating?">My performance</button>
            <button type="button" data-question="How do I apply for leave?">How to apply leave</button>
        </div>

        <form id="aiForm" class="ai-input-row" autocomplete="off">
            <input id="aiQuestion" type="text" maxlength="500"
                   placeholder="Ask an HRIS question..." aria-label="Ask an HRIS question" required>
            <button type="submit" class="btn btn-primary">Send</button>
        </form>
        <div class="ai-note">Role-aware HRIS assistant • Tagalog question → Tagalog answer • English question → English answer.</div>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('aiForm');
    const input = document.getElementById('aiQuestion');
    const messages = document.getElementById('aiMessages');
    const endpoint = <?= json_encode(siteUrl('api/controllers/ApiAssistantController.php')) ?>;

    function addMessage(text, type) {
        const row = document.createElement('div');
        row.className = 'ai-message ' + (type === 'user' ? 'ai-user' : 'ai-bot');
        if (type !== 'user') {
            const avatar = document.createElement('div');
            avatar.className = 'ai-avatar';
            avatar.textContent = 'AI';
            row.appendChild(avatar);
        }
        const bubble = document.createElement('div');
        bubble.className = 'ai-bubble';
        bubble.textContent = text;
        row.appendChild(bubble);
        messages.appendChild(row);
        messages.scrollTop = messages.scrollHeight;
    }

    async function ask(question) {
        question = question.trim();
        if (!question) return;
        addMessage(question, 'user');
        input.value = '';
        input.disabled = true;

        try {
            const body = new URLSearchParams();
            body.set('question', question);
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body: body.toString()
            });
            const data = await response.json();
            addMessage(data.message || 'No response was returned.', 'bot');
        } catch (error) {
            addMessage('The assistant could not connect to the HRIS server. Please try again.', 'bot');
        } finally {
            input.disabled = false;
            input.focus();
        }
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        ask(input.value);
    });

    document.querySelectorAll('.ai-suggestions button').forEach(btn => {
        btn.addEventListener('click', () => ask(btn.dataset.question));
    });
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
