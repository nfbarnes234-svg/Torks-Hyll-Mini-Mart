document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll("[data-confirm]").forEach((form) => {
    form.addEventListener("submit", (event) => {
      if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
  });

  const payment = document.querySelector("#payment-method");
  const split = document.querySelector(".split-fields");
  const discount = document.querySelector('input[name="discount"]');
  const totalEl = document.querySelector("#pos-total");
  const paid = document.querySelector('input[name="amount_paid"]');

  const updateTotals = () => {
    if (!totalEl) return;
    const subtotal = Number(totalEl.dataset.subtotal || 0);
    const discountValue = Math.max(0, Number(discount?.value || 0));
    const taxRate = Number(totalEl.dataset.taxRate || 0);
    const net = Math.max(0, subtotal - discountValue);
    const total = Math.round((net + (net * taxRate / 100)) * 100) / 100;
    totalEl.textContent = new Intl.NumberFormat("en-GH", { style: "currency", currency: "GHS" }).format(total);
    if (paid && (!paid.dataset.touched || Number(paid.value) < subtotal)) paid.value = total.toFixed(2);
    document.querySelectorAll('.split-fields input').forEach(input => {
      if (!input.dataset.auto) { input.dataset.auto = "1"; input.value = "0"; }
    });
  };
  if (paid) paid.addEventListener('input', () => { paid.dataset.touched = "1"; });
  if (discount) discount.addEventListener('input', updateTotals);
  updateTotals();

  if (payment && split) {
    const update = () => {
      split.classList.toggle("visible", payment.value === "split");
      const amount = document.querySelector('input[name="amount_paid"]');
      if (amount) amount.required = payment.value !== "split";
    };
    payment.addEventListener("change", update);
    update();
  }

  window.setTimeout(() => document.querySelectorAll(".toast").forEach((el) => el.remove()), 5200);
});
