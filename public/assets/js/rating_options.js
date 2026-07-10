document.addEventListener('DOMContentLoaded', function(){
    var ratingGroups = document.querySelectorAll('[data-rating-options]');

    ratingGroups.forEach(function(ratingGroup){
        var options = ratingGroup.querySelectorAll('.rating-option');

        options.forEach(function(option){
            var radio = option.querySelector('input[type="radio"]');

            if(!radio){
                return;
            }

            radio.addEventListener('change', function(){
                options.forEach(function(item){
                    item.classList.remove('selected');
                });

                option.classList.add('selected');
            });
        });
    });
});