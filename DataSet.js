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
      timeout = 150,
      unloading = false,
      progressLabel = $('#dataset_file_' + fileid + ' span'),
      args = encodeURI('module=DataSet&function=' + command + '&pageId=' + pageid + '&title=' + title + '&file=' + pathname);

  // alert(taskerAdminApiUrl + '/?cmd=create&' + args);

  progressLabel.text('Creating a task to ' + command + ' the file...');
  // progressLabel.replaceWith = 'Creating a task to ' + command + ' the file';

  // signal if the user is leaving the page
  $(window).bind('beforeunload', function() { unloading = true; });

  // send the HTTP request
  performApiCall(taskerAdminApiUrl + '/?cmd=create&' + args, createCallback);

  function performApiCall(url, callback) {
    $.ajax({
      dataType: "json",
      url: url,
      success: callback,
//      timeout: timeout,
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
			progressLabel.text('Task "' + title + '" has been created.');
			// start the task now
			performApiCall(taskerAdminApiUrl + '/?cmd=start&id=' + data['taskid'], startCallback);
    } else { // return status is not OK
      progressLabel.text('Error: ' + data['result']);
    }
  }

  // callback for task activation
  function startCallback(data) {
    if (data['status']) { // return status is OK
			progressLabel.html('Task "' + title + '" has been started.'
			  + '. <a href="' + taskerAdminUrl + '" target="_blank">Check status</a>');
    } else { // return status is not OK
      progressLabel.text('Error: ' + data['result']);
    }
  }

}
