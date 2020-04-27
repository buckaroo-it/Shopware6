import * as convert from './helpers/convert.js';

export default class Shopware {
  getItems(country_code) {
    if (typeof is_product_detail_page !== "undefined" && is_product_detail_page === true) {
      var all_items = [];
      const send_data = {
        product_id: order_number,
        qty: $("#sQuantity").val() || 1,
        country_code: country_code
      }
      
      $.ajax({
        url: "/Buckaroo/getItemsFromDetailPage",
        data: send_data,
        async: false,
        dataType: "json"
      })
      .done((items) => { 
        all_items = items.map((item) => {
          return {
            id: item.id,
            order_number: item.order_number,
            name: item.name,
            price: convert.toDecimal(item.price),
            qty: item.qty,
            type: item.type
          }
        }); 
      });                
      return all_items;
    }
  }

  getShippingMethods(country_code) {
    const product_params = (() => {
      if (typeof is_product_detail_page !== "undefined" && is_product_detail_page === true) {
        const qty = $("#sQuantity").val() 
          ? $("#sQuantity").val() 
          : 0
        return {
          product_id: order_number,
          article_qty: qty
        }
      }
      return {};
    })();

    const url_params = {
      payment_method: 'buckaroo_applepay',
      country_code: country_code
    }

    var methods;
    $.ajax({
      url: '/Buckaroo/getShippingMethods',
      data: Object.assign(url_params, product_params),
      dataType: "json",
      async: false
    })
    .done((response) => { methods = response; });
    
    return methods;
  }

  getStoreInformation() {
    var result = this.makeRequest('/Buckaroo/getShopInformation');
    return result;
  }

  makeRequest(url, method = 'GET', data = false) {

    console.log("====applepay====makeRequest1");
    console.log(window.accessKey, window.contextToken);

    var information = [];
    var xhr = new XMLHttpRequest();
    xhr.open(method, url, false);
    if (method == 'POST') {
      console.log("====applepay====makeRequest2", url);

      xhr.setRequestHeader("Content-Type", "application/json");
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.setRequestHeader('sw-access-key', window.accessKey);
      xhr.setRequestHeader('sw-context-token', window.contextToken);

      if (window.csrf.enabled && window.buckaroo && window.buckaroo.csrf) {
        data['_csrf_token'] = window.buckaroo.csrf[url];
      }

      xhr.send(JSON.stringify(data));
    } else {

      console.log("====applepay====makeRequest3");
      xhr.send();

    }
    if (xhr.status === 200) {
      if (xhr.responseText.length > 0) {
        try {
          information = JSON.parse(xhr.responseText);
          console.log("====applepay====makeRequest4");
          console.log(information);
        } catch (e) {
          console.log("====applepay====makeRequest5");
        }

      }
    } else {
      console.log("====applepay====makeRequest5");
    }
    return information;
  }

  displayErrorMessage(message) {
    const content = `
      <div class="alert is--warning is--rounded">
        <div class="alert--icon"><i class="icon--element icon--warning"></i></div>
        <div class="alert--content">${message}</div>
      </div>`;
    
    $(".content--wrapper").first().prepend(content);
  }
}