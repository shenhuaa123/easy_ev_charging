document.addEventListener('DOMContentLoaded', function(){
    var forms = document.querySelectorAll('form[data-submit-lock]');

    forms.forEach(function(form){
        form.addEventListener('submit', function(event){
            var submitButton = event.submitter;

            if(!submitButton || submitButton.tagName.toLowerCase() !== 'button'){
                submitButton = form.querySelector('button[type="submit"]');
            }

            if(!submitButton){
                return;
            }

            if(form.dataset.submitting === '1'){
                event.preventDefault();
                return;
            }

            form.dataset.submitting = '1';
            submitButton.disabled = true;

            var loadingText = submitButton.dataset.loadingText || '正在提交...';
            submitButton.dataset.originalText = submitButton.textContent;
            submitButton.textContent = loadingText;
        });
    });
});