document.addEventListener('DOMContentLoaded', function() {
    const voteButtons = document.querySelectorAll('.helpful-article-vote-yes, .helpful-article-vote-no');

    voteButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            handleVote(button.closest('.helpful-article-container'));
        });
    });

    function handleVote(container) {
        const postId = container.dataset.postId;
        const nonce = container.dataset.nonce;
        const action = container.dataset.ajaxAction;
        const vote = container.querySelector('.helpful-article-vote-yes') === event.target ? 1 : 0;

        const xhr = new XMLHttpRequest();
        xhr.open('POST', container.dataset.ajaxUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {

                const divBeforeVote = document.querySelector('.helpful-article-container.helpful-article-before-vote[data-post-id="' + postId + '"]');
                if (divBeforeVote) {
                    divBeforeVote.style.display = 'none';
                }

                const divAfterVote = document.querySelector('.helpful-article-container.helpful-article-after-vote[data-post-id="' + postId + '"]');
                if (divAfterVote) {
                    const jsonResponse = JSON.parse(xhr.responseText);

                    const buttons = divAfterVote.querySelectorAll('btn');
                    buttons.forEach(function(button) {
                        button.innerText = jsonResponse.data['votes-'+button.dataset.vote];
                        if (button.dataset.vote == jsonResponse.data['activate']) {
                            button.classList.add('helpful-article-voted-active');
                        }
                    });

                    var questionDiv = divAfterVote.querySelector('.helpful-article-question');

                    if (questionDiv) {
                        questionDiv.innerText = jsonResponse.data['message'];
                    }

                    divAfterVote.style.display = 'block';
                }
            }
        };
        xhr.send('action='+action+'&post_id=' + postId + '&nonce=' + nonce + '&vote=' + vote);
    }
});
