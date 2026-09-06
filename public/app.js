const halls = [
    { id: "collins", name: "Collins", college: "Claremont McKenna" },
    { id: "mcconnel", name: "McConnell", college: "Pitzer" },
    { id: "malott", name: "Malott", college: "Scripps" },
    { id: "frank", name: "Frank", college: "Pomona" },
    { id: "frary", name: "Frary", college: "Pomona" },
    { id: "oldenborg", name: "Oldenborg", college: "Pomona" },
    { id: "hoch", name: "Hoch-Shanahan", college: "Harvey Mudd" }
];

const elements = {
    form: document.querySelector("#controls"),
    date: document.querySelector("#menu-date"),
    endpoint: document.querySelector("#api-endpoint"),
    grid: document.querySelector("#hall-grid"),
    template: document.querySelector("#hall-template"),
    runState: document.querySelector("#run-state"),
    passing: document.querySelector("#passing-count"),
    meals: document.querySelector("#meal-count"),
    items: document.querySelector("#item-count"),
    elapsed: document.querySelector("#elapsed-time"),
    lastRun: document.querySelector("#last-run")
};

const cards = new Map();

function todayInLosAngeles(){
    const parts = new Intl.DateTimeFormat("en-CA", {
        timeZone: "America/Los_Angeles",
        year: "numeric",
        month: "2-digit",
        day: "2-digit"
    }).formatToParts(new Date());
    const values = Object.fromEntries(parts.map(part => [part.type, part.value]));
    return `${values.year}-${values.month}-${values.day}`;
}

function createCards(){
    elements.grid.replaceChildren();
    for(const hall of halls){
        const card = elements.template.content.firstElementChild.cloneNode(true);
        card.dataset.hall = hall.id;
        card.querySelector(".college-name").textContent = hall.college;
        card.querySelector(".hall-name").textContent = hall.name;
        cards.set(hall.id, card);
        elements.grid.append(card);
    }
}

function setCardState(card, state, label, message){
    card.classList.remove("is-loading", "is-pass", "is-fail", "is-empty", "is-closed");
    card.classList.add(`is-${state}`);
    card.querySelector(".status-pill").textContent = label;
    card.querySelector(".hall-message").textContent = message;
}

function formatTime(timestamp){
    if(!Number.isFinite(Number(timestamp)) || Number(timestamp) <= 0){return "";}
    return new Intl.DateTimeFormat("en-US", {
        timeZone: "America/Los_Angeles",
        hour: "numeric",
        minute: "2-digit"
    }).format(new Date(Number(timestamp) * 1000));
}

function normalizeDate(value){
    const digits = String(value || "").replaceAll(/[^0-9]/g, "");
    return digits.length === 8 ? `${digits.slice(0, 4)}-${digits.slice(4, 6)}-${digits.slice(6, 8)}` : String(value || "").slice(0, 10);
}

function summarize(payload, requestedDate){
    if(!payload || !Array.isArray(payload.menu)){
        throw new Error("Response is missing the menu array");
    }

    const meals = [];
    let itemCount = 0;
    let stationCount = 0;

    const matchingDays = payload.menu.filter(day => normalizeDate(day?.date) === requestedDate);
    if(matchingDays.length === 0){throw new Error(`Response has no entry for ${requestedDate}`);}
    const closed = payload.diningHallOpen === false || matchingDays.every(day => day.open === false);

    for(const day of matchingDays){
        if(!day || typeof day.meals !== "object" || Array.isArray(day.meals)){continue;}
        for(const [mealKey, meal] of Object.entries(day.meals)){
            if(!meal || !Array.isArray(meal.stations)){continue;}
            stationCount += meal.stations.length;
            const items = meal.stations.reduce((total, station) => total + (Array.isArray(station.menu) ? station.menu.length : 0), 0);
            itemCount += items;
            meals.push({ key: mealKey, ...meal, itemCount: items });
        }
    }

    return { meals, itemCount, stationCount, closed };
}

function escapeHTML(value){
    return String(value)
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}

function renderMeals(card, meals){
    const container = card.querySelector(".meals");
    container.replaceChildren();

    for(const meal of meals){
        const details = document.createElement("details");
        details.className = "meal";
        const summary = document.createElement("summary");
        const times = [formatTime(meal.startTime), formatTime(meal.endTime)].filter(Boolean).join("–");
        summary.innerHTML = `<span><strong>${escapeHTML(meal.meal || meal.key)}</strong>${times ? `<small>${times}</small>` : ""}</span><span>${meal.itemCount} items</span>`;
        details.append(summary);

        for(const station of meal.stations){
            if(!Array.isArray(station.menu) || station.menu.length === 0){continue;}
            const stationElement = document.createElement("section");
            stationElement.className = "station";
            const title = document.createElement("h4");
            title.textContent = station.station || "Station";
            stationElement.append(title);

            const list = document.createElement("ul");
            for(const item of station.menu){
                const row = document.createElement("li");
                const badges = [item.vegan ? "V" : "", !item.vegan && item.vegetarian ? "VG" : ""].filter(Boolean);
                row.innerHTML = `<span><strong>${escapeHTML(item.name || "Unnamed item")}</strong>${item.description ? `<small>${escapeHTML(item.description)}</small>` : ""}</span>${badges.map(badge => `<em>${badge}</em>`).join("")}`;
                list.append(row);
            }
            stationElement.append(list);
            details.append(stationElement);
        }
        container.append(details);
    }
}

async function fetchHall(hall, endpoint, date){
    const card = cards.get(hall.id);
    card.querySelector(".meals").replaceChildren();
    card.querySelector(".hall-meta").hidden = true;
    setCardState(card, "loading", "Fetching", "Calling the local API…");

    const url = new URL(endpoint, window.location.href);
    url.searchParams.set("diningHall", hall.id);
    url.searchParams.set("startDate", date);
    url.searchParams.set("source", "live");
    url.searchParams.set("developer", "true");

    const started = performance.now();
    const response = await fetch(url, { headers: { Accept: "application/json" }, cache: "no-store" });
    const raw = await response.text();
    let payload;
    try{
        payload = JSON.parse(raw);
    }
    catch{
        throw new Error(`API returned non-JSON content (${response.status})`);
    }
    if(!response.ok){throw new Error(payload.error || `Request failed with ${response.status}`);}

    const result = summarize(payload, date);
    const elapsed = Math.round(performance.now() - started);
    const meta = card.querySelector(".hall-meta");
    meta.hidden = false;
    meta.querySelector(".meta-count").textContent = `${result.meals.length} meals · ${result.itemCount} items · ${elapsed}ms`;
    const debug = payload.debug || {};
    meta.querySelector(".meta-source").textContent = debug.mode && debug.source ? `${debug.mode} · ${new URL(debug.source).hostname}` : "legacy parser";

    if(result.closed){
        setCardState(card, "closed", "Closed", "The API confirms this dining hall is closed for the selected date.");
        return { ok: true, mealCount: 0, itemCount: 0 };
    }

    if(result.itemCount === 0){
        setCardState(card, "empty", "Empty", "The API responded, but no menu items were present for this date.");
        return { ok: false, mealCount: result.meals.length, itemCount: 0 };
    }

    renderMeals(card, result.meals);
    setCardState(card, "pass", "Loaded", `${result.stationCount} stations returned valid menu data.`);
    return { ok: true, mealCount: result.meals.length, itemCount: result.itemCount };
}

async function run(){
    const button = elements.form.querySelector("button");
    const endpoint = elements.endpoint.value.trim();
    const date = elements.date.value;
    const started = performance.now();

    button.disabled = true;
    elements.runState.className = "run-state is-running";
    elements.runState.lastElementChild.textContent = "Checking all halls";
    elements.passing.textContent = "0";
    elements.meals.textContent = "0";
    elements.items.textContent = "0";

    const results = await Promise.all(halls.map(async hall => {
        try{
            return await fetchHall(hall, endpoint, date);
        }
        catch(error){
            const card = cards.get(hall.id);
            setCardState(card, "fail", "Failed", error.message);
            card.querySelector(".hall-meta").hidden = true;
            return { ok: false, mealCount: 0, itemCount: 0 };
        }
    }));

    const passing = results.filter(result => result.ok).length;
    const mealCount = results.reduce((sum, result) => sum + result.mealCount, 0);
    const itemCount = results.reduce((sum, result) => sum + result.itemCount, 0);
    const elapsed = (performance.now() - started) / 1000;

    elements.passing.textContent = String(passing);
    elements.meals.textContent = String(mealCount);
    elements.items.textContent = String(itemCount);
    elements.elapsed.textContent = `${elapsed.toFixed(1)}s`;
    elements.lastRun.textContent = `Last run ${new Intl.DateTimeFormat("en-US", { timeStyle: "medium" }).format(new Date())}`;
    elements.runState.className = `run-state ${passing === halls.length ? "is-good" : "is-bad"}`;
    const failed = halls.length - passing;
    elements.runState.lastElementChild.textContent = failed === 0 ? "All responses verified" : `${failed} menu${failed === 1 ? "" : "s"} need attention`;
    button.disabled = false;
}

elements.form.addEventListener("submit", event => {
    event.preventDefault();
    run();
});

elements.date.value = todayInLosAngeles();
createCards();
run();
