(function () {
  var status = document.getElementById('rb-qr-scan-status');
  var video = document.getElementById('rb-qr-video');
  var startButton = document.getElementById('rb-qr-start');
  var input = document.getElementById('rb-qr-input');
  var amountInput = document.getElementById('rb-qr-pay-amount');
  var cameraStream = null;
  var cameraStarting = false;
  var lastScanTime = 0;
  var scanCanvas = document.createElement('canvas');
  var scanContext = scanCanvas.getContext('2d', { willReadFrequently: true });

  function setStatus(message) {
    if (status) {
      status.textContent = message;
    }
  }

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
      setStatus('QR code could not be generated. Refresh the page and try again.');
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
        setStatus('QR image is not ready yet.');
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
    continuePayment(input.value, amountInput.value);
  });

  function stopCamera() {
    if (cameraStream) {
      cameraStream.getTracks().forEach(function (track) { track.stop(); });
      cameraStream = null;
    }
    video.pause();
    video.srcObject = null;
    video.hidden = true;
    startButton.disabled = false;
    startButton.textContent = 'Use camera';
  }

  function cameraErrorMessage(error) {
    if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
      return 'Camera access requires HTTPS. Open this site using its secure HTTPS address.';
    }
    if (error && (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError')) {
      return 'Camera permission was denied. Allow camera access for this site in your browser settings, then try again.';
    }
    if (error && error.name === 'NotFoundError') {
      return 'No camera was found on this device. Enter the in-app virtual ID manually.';
    }
    if (error && error.name === 'NotReadableError') {
      return 'The camera is busy in another app or tab. Close it there and try again.';
    }
    return 'Could not start the camera. Check HTTPS, browser camera permission, and that no other app is using the camera.';
  }

  function scanFrame() {
    if (!cameraStream) {
      return;
    }
    if (video.readyState < 2 || !video.videoWidth || !video.videoHeight) {
      window.requestAnimationFrame(scanFrame);
      return;
    }
    var now = Date.now();
    if (now - lastScanTime < 150) {
      window.requestAnimationFrame(scanFrame);
      return;
    }
    lastScanTime = now;
    var maxDimension = 1280;
    var scale = Math.min(1, maxDimension / Math.max(video.videoWidth, video.videoHeight));
    var width = Math.max(1, Math.round(video.videoWidth * scale));
    var height = Math.max(1, Math.round(video.videoHeight * scale));
    scanCanvas.width = width;
    scanCanvas.height = height;
    try {
      scanContext.drawImage(video, 0, 0, width, height);
      var frame = scanContext.getImageData(0, 0, width, height);
      var result = window.jsQR(frame.data, frame.width, frame.height, { inversionAttempts: 'attemptBoth' });
      if (result && result.data) {
        var scannedValue = result.data;
        stopCamera();
        continuePayment(scannedValue, amountInput.value);
        return;
      }
    } catch (error) {
      stopCamera();
      setStatus('The camera started, but the QR image could not be read. Enter the in-app virtual ID manually.');
      return;
    }
    window.requestAnimationFrame(scanFrame);
  }

  function startCamera() {
    if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
      setStatus('Camera access requires HTTPS. Open this site using its secure HTTPS address.');
      return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      setStatus('This browser does not provide camera access. Use HTTPS or enter the in-app virtual ID manually.');
      return;
    }
    if (!window.jsQR) {
      setStatus('The local QR scanner did not load. Refresh the page and try again.');
      return;
    }
    if (cameraStream) {
      stopCamera();
      setStatus('Camera stopped.');
      return;
    }
    cameraStarting = true;
    startButton.disabled = true;
    startButton.textContent = 'Starting camera...';
    navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false }).then(function (stream) {
      cameraStarting = false;
      startButton.disabled = false;
      cameraStream = stream;
      video.srcObject = stream;
      video.muted = true;
      video.playsInline = true;
      video.hidden = false;
      startButton.textContent = 'Stop camera';
      return video.play();
    }).then(function () {
      setStatus('Point the camera at a Bank Of Soumya QR. Scanning is performed on this device.');
      window.requestAnimationFrame(scanFrame);
    }).catch(function (error) {
      cameraStarting = false;
      startButton.disabled = false;
      stopCamera();
      setStatus(cameraErrorMessage(error));
    });
  }

  startButton.addEventListener('click', startCamera);
  document.addEventListener('visibilitychange', function () {
    if (document.hidden && cameraStream) {
      stopCamera();
      setStatus('Camera stopped because this page is no longer visible.');
    }
  });
  window.addEventListener('pagehide', stopCamera);
}());
