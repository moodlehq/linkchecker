function linkchecker() {
        checks = node.all('.manualcheck');
        checks.each(function(node){
          var response = getheadchecked(node.id, node.url);
          node.setHTML(response);
        });
}

function getheadchecked(id, url) {
    Y.io(uri, {
            method: 'POST',
            data: 'url',
            on: {
                start: function() {
                    spinner.show();
                },
                success: function(tid, response) {
                    var responsetext = Y.JSON.parse(response.responseText);
                    Y.log(responsetext);
                    window.setTimeout(function() {
                        spinner.hide();
                    }, 250);
                    return responsetext;
                },
                failure: function(tid, response) {
                    this.ajax_failure(response);
                    spinner.hide();
                }
            }
    });
}