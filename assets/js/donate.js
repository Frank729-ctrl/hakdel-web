/* about/index.php — Paystack donation handler
   Requires: Paystack inline.js loaded before this script. */

function initDonate() {
  const email = prompt('Enter your email for the donation receipt:');
  if (!email || !email.includes('@')) {
    alert('Please enter a valid email address.');
    return;
  }

  const amount = prompt('Enter donation amount in GHS (minimum 5):');
  if (!amount || isNaN(amount) || parseInt(amount) < 5) {
    alert('Please enter a valid amount (minimum GHS 5).');
    return;
  }

  const handler = PaystackPop.setup({
    key:      'pk_test_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', // Replace with your Paystack public key
    email:    email,
    amount:   parseInt(amount) * 100, // Paystack uses pesewas
    currency: 'GHS',
    ref:      'hakdel_' + Math.floor(Math.random() * 1000000000),
    metadata: {
      custom_fields: [
        { display_name: 'Platform', variable_name: 'platform', value: 'HakDel' }
      ]
    },
    callback: function (response) {
      alert('Thank you for your support! Reference: ' + response.reference);
    },
    onClose: function () {
      // User closed the popup
    }
  });
  handler.openIframe();
}
