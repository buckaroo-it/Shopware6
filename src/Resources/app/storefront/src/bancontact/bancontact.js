
document.getElementById("bancontactmrcash_expirationmonth").addEventListener("keyup", function(event) {
    var val = $(this).val();
    if (!isNaN(val)) {
        if (val > 1 && val < 10 && val.length == 1) {
            temp_val = "0" + val + "/";
            $(this).val(temp_val);
        } else if (val >= 1 && val < 10 && val.length == 2 && event.keyCode != 8) {
            temp_val = val + "/";
            $(this).val(temp_val);
        } else if (val > 9 && val.length == 2 && event.keyCode != 8) {
            temp_val = val + "/";
            $(this).val(temp_val);
        }
    } else {
        // Handle non-numeric input here if needed
    }
});