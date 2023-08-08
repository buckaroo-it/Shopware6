import Plugin from 'src/plugin-system/plugin.class';

export default class IdealQrPlugin extends Plugin {

    static options = {
        transactionKey: null
    }

    init()
    {
       this.setupWebSocketChannel(this.options.transactionKey);
    }

    setupWebSocket(url, onMessageEvent) {
        const self = this;
        let socket = new WebSocket(url);
        socket.onclose = function (e) {
            setTimeout(function () {
                self.setupWebSocket(url, onMessageEvent);
            }, 200);
        };
        socket.onerror = function (err) {
            socket.close();
        };
        socket.onmessage = onMessageEvent;
    };
    setupWebSocketChannel(transactionKey) {
        var url = "wss://websocketservice-externalapi.prod.buckaroo.io/IdealQr/" + transactionKey;
        this.setupWebSocket(url, function (event) {
            // get response object from event
            var responseObj = JSON.parse(event.data);
            switch (responseObj.status) {
                case "SUCCESS":
                case "FAILED":
                    setTimeout(function () { window.location.href = responseObj.redirectUrl; }, 5000);
                    break;
            }
        });
    }
}