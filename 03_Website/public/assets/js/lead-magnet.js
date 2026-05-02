(function () {
  const planForm = document.querySelector("[data-plan-form]");
  const result = document.querySelector("[data-plan-result]");
  const copyButton = document.querySelector("[data-copy-plan]");
  const saveButton = document.querySelector("[data-save-plan]");

  const rituals = {
    energy: [
      "Drink water before coffee and take a 6-minute walk.",
      "Open a window, breathe slowly for 10 rounds, then stretch your hips.",
      "Choose one protein-rich meal anchor before noon."
    ],
    confidence: [
      "Stand tall, name one body win, and do 8 slow squats.",
      "Wear something that helps you feel present in your body.",
      "Write one sentence that starts with: I keep promises to myself by..."
    ],
    calm: [
      "Place one hand on your chest and breathe out longer than you breathe in.",
      "Do a gentle neck and shoulder reset before checking your phone.",
      "End the day by writing one thing your body helped you do."
    ],
    consistency: [
      "Attach movement to something you already do every day.",
      "Set a minimum version of your workout that takes less than 5 minutes.",
      "Track the ritual with a simple yes/no mark."
    ]
  };

  const movementByTime = {
    "5": "5 minutes: mobility flow, wall pushups, or a short walk.",
    "10": "10 minutes: brisk walk, yoga flow, or bodyweight circuit.",
    "20": "20 minutes: strength basics, longer walk, or yoga plus breathwork."
  };

  function getFormData() {
    return {
      focus: planForm.querySelector("[name='focus']").value,
      energy: planForm.querySelector("[name='energy']").value,
      time: planForm.querySelector("[name='time']").value
    };
  }

  function buildPlan(data) {
    const focusRituals = rituals[data.focus] || rituals.energy;
    const movement = movementByTime[data.time] || movementByTime["10"];
    const energyLine = data.energy === "low"
      ? "Keep the effort gentle enough that you could repeat it tomorrow."
      : data.energy === "high"
        ? "Use the extra energy, but stop while it still feels clean and repeatable."
        : "Choose a steady pace and let the ritual be enough.";

    return Array.from({ length: 7 }, (_, index) => {
      const ritual = focusRituals[index % focusRituals.length];
      return {
        day: index + 1,
        intention: [
          "I am becoming someone who follows through kindly.",
          "My body is part of my vision.",
          "Small rituals can create visible momentum.",
          "I can choose progress without pressure.",
          "My energy responds to what I repeat.",
          "I honor the version of me I am building.",
          "I continue with trust and patience."
        ][index],
        movement,
        ritual,
        reflection: energyLine
      };
    });
  }

  function renderPlan(plan) {
    result.innerHTML = plan.map((item) => `
      <article class="day-plan">
        <p class="day-plan__label">Day ${item.day}</p>
        <h3>${item.intention}</h3>
        <p><strong>Move:</strong> ${item.movement}</p>
        <p><strong>Ritual:</strong> ${item.ritual}</p>
        <p><strong>Reflect:</strong> ${item.reflection}</p>
      </article>
    `).join("");
    result.dataset.hasPlan = "true";
  }

  function getPlanText() {
    return Array.from(result.querySelectorAll(".day-plan")).map((day) => day.innerText.trim()).join("\n\n");
  }

  if (planForm) {
    planForm.addEventListener("submit", (event) => {
      event.preventDefault();
      const plan = buildPlan(getFormData());
      renderPlan(plan);
      localStorage.setItem("manifestedFitLatestPlan", JSON.stringify({
        plan,
        createdAt: new Date().toISOString()
      }));
    });
  }

  if (copyButton) {
    copyButton.addEventListener("click", async () => {
      const text = getPlanText();
      if (!text) {
        return;
      }
      await navigator.clipboard.writeText(text);
      copyButton.textContent = "Copied";
      setTimeout(() => {
        copyButton.textContent = "Copy Plan";
      }, 1400);
    });
  }

  if (saveButton) {
    saveButton.addEventListener("click", () => {
      const text = getPlanText();
      if (!text) {
        return;
      }
      const blob = new Blob([text], { type: "text/plain" });
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = "manifested-fit-reset-plan.txt";
      link.click();
      URL.revokeObjectURL(url);
    });
  }
})();

