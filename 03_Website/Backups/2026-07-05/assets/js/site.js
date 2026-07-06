(function () {
  const config = window.ManifestedFitConfig || {};
  const offers = window.ManifestedFitOffers || [];

  function getOffer(slug) {
    return offers.find((offer) => offer.slug === slug);
  }

  function getOfferUrl(offer) {
    if (!offer) {
      return config.resourcesPath || "/resources/";
    }
    return offer.url || offer.fallbackUrl || config.resourcesPath || "/resources/";
  }

  function getPrimaryOfferUrl() {
    return getOfferUrl(getOffer(config.primaryOfferSlug));
  }

  function fillTrackingFields(form) {
    const params = new URLSearchParams(window.location.search);
    ["utm_source", "utm_medium", "utm_campaign", "utm_content", "utm_term"].forEach((key) => {
      const input = form.querySelector(`[name="${key}"]`);
      if (input) {
        input.value = params.get(key) || "";
      }
    });
  }

  function showMessage(form, message, type) {
    const output = form.querySelector("[data-form-message]");
    if (!output) {
      return;
    }
    output.textContent = message;
    output.dataset.state = type;
  }

  function setLoading(form, isLoading) {
    const button = form.querySelector("button[type='submit']");
    if (button) {
      button.disabled = isLoading;
      button.textContent = isLoading ? "Sending..." : button.dataset.label || "Get The Reset";
    }
  }

  function localFileSuccess(form) {
    const email = form.querySelector("[name='email']")?.value || "";
    const name = form.querySelector("[name='name']")?.value || "";
    localStorage.setItem("manifestedFitLead", JSON.stringify({
      name,
      email,
      capturedAt: new Date().toISOString(),
      mode: "local-preview"
    }));
    window.location.href = "thank-you/";
  }

  async function handleLeadFormSubmit(event) {
    event.preventDefault();
    const form = event.currentTarget;
    fillTrackingFields(form);

    const email = form.querySelector("[name='email']")?.value || "";
    if (!email.includes("@")) {
      showMessage(form, "Enter a valid email address.", "error");
      return;
    }

    if (window.location.protocol === "file:") {
      localFileSuccess(form);
      return;
    }

    setLoading(form, true);
    showMessage(form, "", "idle");

    try {
      const response = await fetch(form.action, {
        method: "POST",
        body: new FormData(form),
        headers: {
          Accept: "application/json"
        }
      });
      const result = await response.json();
      if (!response.ok || !result.ok) {
        throw new Error(result.error || "The form could not be sent.");
      }
      window.location.href = result.redirect || config.thankYouPath || "/thank-you/";
    } catch (error) {
      showMessage(form, "The form could not be sent yet. Try again after the site is connected.", "error");
    } finally {
      setLoading(form, false);
    }
  }

  document.querySelectorAll("[data-lead-form]").forEach((form) => {
    fillTrackingFields(form);
    const button = form.querySelector("button[type='submit']");
    if (button) {
      button.dataset.label = button.textContent;
    }
    form.addEventListener("submit", handleLeadFormSubmit);
  });

  document.querySelectorAll("[data-affiliate-link]").forEach((link) => {
    const offer = getOffer(link.dataset.affiliateLink || config.primaryOfferSlug);
    link.href = getOfferUrl(offer) || getPrimaryOfferUrl();
    if (offer?.status && offer.status !== "active") {
      link.setAttribute("data-offer-status", offer.status);
    }
  });
})();
