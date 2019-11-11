/*
 * DataSet module - javascript frontend functions
 * 
 * Performs dataset functions on mouse click
 * 
 * Copyright 2018 Tamas Meszaros <mt+git@webit.hu>
 * This file licensed under Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */

function DataSet(command, pageid, title, pathname, fileid) {
  var taskerAdminUrl = ProcessWire.config.tasker.adminUrl,
      taskerAdminApiUrl = ProcessWire.config.tasker.apiUrl,
      timeout = 15000,
      unloading = false,
      progressLabel = $('div#dataset_file_' + fileid + ''),
      args = encodeURI('module=DataSet&function=' + command + '&pageId=' + pageid + '&title=' + title + '&file=' + pathname);

  // alert(taskerAdminApiUrl + '/?cmd=create&' + args);

  progressLabel.text('Creating a task to ' + command + ' the DataSet...');

  // signal if the user is leaving the page
  $(window).bind('beforeunload', function() { unloading = true; });

  // send the HTTP request
  performApiCall(taskerAdminApiUrl + '/?cmd=create&' + args, createCallback);

  function performApiCall(url, callback) {
    $.ajax({
      dataType: "json",
      url: url,
      success: callback,
      timeout: timeout,
      error: function(jqXHR, status, errorThrown) {
        if (status == 'timeout') {
	  progressLabel.text('Request timeout. Please check the backend for more info.');
	} else if (unloading) {
	  progressLabel.text('Cancelling request...');
	} else {
	  progressLabel.text('Error receiving response from the server: ' + status);
	}
      }
    });
  }

  // callback for task creation
  function createCallback(data) {
    if (data['status']) { // return status is OK
      progressLabel.replaceWith(data['status_html']);
    } else { // return status is not OK
      progressLabel.text('Error: ' + data['result']);
    }
  }

}
