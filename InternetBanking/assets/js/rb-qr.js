(function () {
  var status = document.getElementById('rb-qr-scan-status');
  var video = document.getElementById('rb-qr-video');
  var cameraStream = null;

  function buildPayload(token, amount) {
    var payload = 'bankofsoumya://pay/' + token;
    if (amount !== '') {
      payload += '?amount=' + encodeURIComponent(amount);
    }
    return payload;
  }

  function renderCode(element) {
    var token = element.getAttribute('data-token');
    var cardBody = element.closest('.card-body');
    var amountField = cardBody ? cardBody.querySelector('.rb-qr-amount') : null;
    var amount = amountField ? amountField.value.trim() : '';
    element.innerHTML = '';
    if (!window.QRCode || !token) {
      status.textContent = 'QR code could not be generated. Refresh the page and try again.';
      return;
    }
    new QRCode(element, {
      text: buildPayload(token, amount),
      width: 220,
      height: 220,
      correctLevel: QRCode.CorrectLevel.M
    });
  }

  Array.prototype.forEach.call(document.querySelectorAll('.rb-qr-image'), function (element) {
    renderCode(element);
    var cardBody = element.closest('.card-body');
    var amountField = cardBody ? cardBody.querySelector('.rb-qr-amount') : null;
    if (amountField) {
      amountField.addEventListener('input', function () {
        renderCode(element);
      });
    }
  });

  Array.prototype.forEach.call(document.querySelectorAll('.rb-qr-download'), function (button) {
    button.addEventListener('click', function () {
      var target = document.getElementById(button.getAttribute('data-target'));
      var image = target ? target.querySelector('img') : null;
      var canvas = target ? target.querySelector('canvas') : null;
      var dataUrl = image && image.src ? image.src : (canvas ? canvas.toDataURL('image/png') : '');
      if (!dataUrl) {
        status.textContent = 'QR image is not ready yet.';
        return;
      }
      var link = document.createElement('a');
      link.href = dataUrl;
      link.download = 'bank-of-soumya-qr.png';
      link.click();
    });
  });

  function continuePayment(input, fallbackAmount) {
    var value = input.trim();
    var tokenMatch = value.match(/^(?:bankofsoumya|ruralbank-demo):\/\/pay\/([a-f0-9]{32})(?:\?amount=([0-9]+(?:\.[0-9]{1,2})?))?$/i);
    var upiMatch = value.match(/^[a-z0-9._-]{4,32}@(bankofsoumya\.local|ruralbank\.demo)$/i);
    var token = tokenMatch ? tokenMatch[1] : '';
    var upi = upiMatch ? value : '';
    var amount = tokenMatch && tokenMatch[2] ? tokenMatch[2] : fallbackAmount.trim();
    if (!token && !upi) {
      status.textContent = 'Enter a valid in-app virtual ID or scan a Bank Of Soumya QR.';
      return;
    }
    if (amount !== '' && !/^\d{1,8}(?:\.\d{1,2})?$/.test(amount)) {
      status.textContent = 'Enter a positive amount with up to two decimals.';
      return;
    }
    var query = token ? 'qr_token=' + encodeURIComponent(token) : 'upi_id=' + encodeURIComponent(upi);
    if (amount !== '') {
      query += '&amount=' + encodeURIComponent(amount);
    }
    window.location.href = 'pages_send_money.php?' + query;
  }

  document.getElementById('rb-qr-continue').addEventListener('click', function () {
    continuePayment(document.getElementById('rb-qr-input').value, document.getElementById('rb-qr-pay-amount').value);
  });

  function stopCamera() {
    if (cameraStream) {
      cameraStream.getTracks().forEach(function (track) { track.stop(); });
      cameraStream = null;
    }
    video.hidden = true;
  }

  document.getElementById('rb-qr-start').addEventListener('click', function () {
    if (!('BarcodeDetector' in window) || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      status.textContent = 'This browser does not support camera QR scanning. Enter the in-app virtual ID instead.';
      return;
    }
    if (cameraStream) {
      return;
    }
    navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } } }).then(function (stream) {
      cameraStream = stream;
      video.srcObject = stream;
      video.hidden = false;
      return video.play();
    }).then(function () {
      var detector = new BarcodeDetector({ formats: ['qr_code'] });
      status.textContent = 'Point the camera at a Bank Of Soumya QR.';
      function scan() {
        if (!cameraStream) {
          return;
        }
        detector.detect(video).then(function (codes) {
          if (codes.length && codes[0].rawValue) {
            var content = codes[0].rawValue;
            stopCamera();
            continuePayment(content, document.getElementById('rb-qr-pay-amount').value);
          } else {
            window.requestAnimationFrame(scan);
          }
        }).catch(function () {
          stopCamera();
          status.textContent = 'Could not read the QR. Enter the in-app virtual ID manually.';
        });
      }
      scan();
    }).catch(function () {
      stopCamera();
      status.textContent = 'Camera permission or QR scanning is unavailable. Enter the in-app virtual ID manually.';
    });
  });
}());
