var barcode = require('./barcode.js');
var qrcode = require('./qrcode.js');

function convert_length(length) {
	return Math.round(wx.getWindowInfo().windowWidth * length / 750);
}

function bar_code(id, code, width, height) {
	barcode.code128(wx.createCanvasContext(id), code, convert_length(width), convert_length(height))
}

function qr_code(id, code, width, height) {
	qrcode.api.draw(code, {
		ctx: wx.createCanvasContext(id),
		width: convert_length(width),
		height: convert_length(height)
	})
}

module.exports = {
	barcode: bar_code,
	qrcode: qr_code
}