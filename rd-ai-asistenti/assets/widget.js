(function () {
    if (typeof rdaiConfig === 'undefined') return;

    var form = document.getElementById('rdai-form');
    var input = document.getElementById('rdai-input');
    var messages = document.getElementById('rdai-messages');
    if (!form) return;

    var history = [];

    function addMessage(text, cls, isHtml) {
        var div = document.createElement('div');
        div.className = 'rdai-msg ' + cls;
        if (isHtml) div.innerHTML = text;
        else div.textContent = text;
        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;
        return div;
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var otazka = input.value.trim();
        if (!otazka) return;

        addMessage(otazka, 'rdai-msg-user');
        input.value = '';
        input.disabled = true;
        form.querySelector('button').disabled = true;

        var loading = addMessage('<span class="rdai-typing"><span></span><span></span><span></span></span>', 'rdai-msg-loading', true);

        var formData = new URLSearchParams();
        formData.append('action', rdaiConfig.action);
        formData.append('nonce', rdaiConfig.nonce);
        formData.append('otazka', otazka);
        formData.append('historie', JSON.stringify(history.slice(-4)));

        fetch(rdaiConfig.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            loading.remove();
            if (data.success) {
                addMessage(data.data.odpoved, 'rdai-msg-bot');
                history.push({role: 'user', text: otazka});
                history.push({role: 'bot', text: data.data.odpoved});
                if (data.data.data) {
                    var details = document.createElement('details');
                    details.className = 'rdai-rawdata';
                    var summary = document.createElement('summary');
                    summary.textContent = 'Zobrazit zdrojová data';
                    var pre = document.createElement('pre');
                    pre.textContent = JSON.stringify(data.data.data, null, 2);
                    details.appendChild(summary);
                    details.appendChild(pre);
                    messages.appendChild(details);
                    messages.scrollTop = messages.scrollHeight;
                }
            } else {
                addMessage(data.data.message || 'Nastala chyba.', 'rdai-msg-bot');
            }
        })
        .catch(function () {
            loading.remove();
            addMessage('Nepodařilo se spojit se serverem. Zkuste to znovu.', 'rdai-msg-bot');
        })
        .finally(function () {
            input.disabled = false;
            form.querySelector('button').disabled = false;
            input.focus();
        });
    });
})();
