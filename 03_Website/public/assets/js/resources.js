(function () {
  const offers = window.ManifestedFitOffers || [];
  const container = document.querySelector("[data-offer-list]");

  function getStatusLabel(status) {
    if (status === "active") {
      return "Recommended";
    }
    if (status === "pending") {
      return "Affiliate pending";
    }
    return "Researching";
  }

  function getActionLabel(offer) {
    if (offer.status === "active" && offer.url) {
      return offer.buttonLabel || "View Resource";
    }
    return offer.buttonLabel || getStatusLabel(offer.status);
  }

  function renderOffer(offer) {
    const href = offer.url || offer.fallbackUrl || "#";
    const isClickable = href !== "#";
    return `
      <article class="offer-card">
        <p class="offer-card__category">${offer.category}</p>
        <div class="offer-card__heading">
          <h3>${offer.name}</h3>
          <span class="status-pill" data-status="${offer.status}">${getStatusLabel(offer.status)}</span>
        </div>
        <p>${offer.summary}</p>
        <p class="offer-card__note">${offer.note}</p>
        <a class="button ${isClickable ? "" : "button--disabled"}" href="${href}" ${isClickable ? 'target="_blank" rel="nofollow sponsored noopener"' : 'aria-disabled="true"'}>${getActionLabel(offer)}</a>
      </article>
    `;
  }

  if (container) {
    container.innerHTML = offers.map(renderOffer).join("");
  }
})();

