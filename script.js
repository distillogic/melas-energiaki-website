const translations = {
  el: {},
  en: {
    skip:"Skip to main content",navHome:"Home",navAbout:"About",navServices:"Services",navContact:"Contact",
    heroEyebrow:"Welcome to MELAS ENERGY",heroTitle:"Empowering the world with affordable solar energy solutions",heroCopy:"From design to operation, we build photovoltaic projects with consistency, expertise and respect for the environment.",seeServices:"Explore our services",talkToUs:"Talk to our team",
    statPanels:"Reused solar panels",tons:"tonnes",statWaste:"Electronic waste prevented",countries:"countries",statRegions:"Across Europe, Africa and Asia",
    fullSupport:"End-to-end support",aboutKicker:"LIGHT WITH PURPOSE",aboutTitle:"Tomorrow's energy, delivered responsibly today",aboutText1:"MELAS ENERGY provides comprehensive services for photovoltaic projects of every scale — from the initial assessment to installation, maintenance and upgrades.",aboutText2:"We give reliable equipment a second life, reduce electronic waste and make clean energy accessible to more markets.",check1:"Specialist technical team",check2:"Solutions tailored to every project",check3:"Full life-cycle support",
    servicesKicker:"FROM PLANNING TO PRODUCTION",servicesTitle:"Our services",servicesIntro:"One trusted partner for every stage of your photovoltaic project.",
    s1t:"Site Selection & Suitability Assessment",s1d:"Assessment of the site and technical requirements for developing a photovoltaic project.",s2t:"Studies & Licensing",s2d:"Preparation of the required studies and support throughout the project licensing process.",s3t:"Site Preparation",s3d:"Fencing, infrastructure construction and preparation of the solar park's auxiliary areas.",s4t:"Equipment Supply & Installation",s4d:"Supply and installation of panels, mounting systems, inverters and all necessary equipment.",s5t:"Electrical & Technical Works",s5d:"Complete electrical connection, wiring and technical installation of the photovoltaic system.",s6t:"Technical Maintenance & Repairs",s6d:"Routine maintenance, technical inspections and rapid response to potential faults.",s7t:"Solar Park Cleaning & Maintenance",s7d:"Panel cleaning, grounds care and work that supports reliable installation operation.",s8t:"Performance Monitoring & Repowering",s8d:"Energy performance monitoring and upgrade planning for older photovoltaic installations.",s9t:"Panel & Equipment Replacement",s9d:"Removal of old equipment and installation of new photovoltaic panels and systems.",s10t:"Solar Park Sales & Acquisitions",s10d:"Support for buying and selling completed or operational photovoltaic projects.",s11t:"Residential Solar Systems",s11d:"Design and installation of residential systems for clean power generation and energy savings.",
    ctaKicker:"YOUR NEXT PROJECT",ctaTitle:"Let's turn sunlight into value.",callNow:"Call us now",contactKicker:"FIND US",contactTitle:"Talk to our team",contactText:"Whether you are planning a new project or need support for an existing installation, we are here to help.",phone:"Phone",footerText:"Complete, reliable and responsible solar energy solutions.",rights:"All rights reserved"
  }
};

const greekText = {};
document.querySelectorAll("[data-i18n]").forEach(el => greekText[el.dataset.i18n] = el.textContent);

function setLanguage(lang) {
  document.documentElement.lang = lang;
  document.querySelectorAll("[data-i18n]").forEach(el => {
    const key = el.dataset.i18n;
    el.textContent = lang === "en" ? translations.en[key] || greekText[key] : greekText[key];
  });
  document.querySelectorAll(".lang-btn").forEach(btn => {
    const selected = btn.dataset.lang === lang;
    btn.classList.toggle("active", selected);
    btn.setAttribute("aria-pressed", selected);
    btn.setAttribute("tabindex", selected ? "0" : "-1");
  });
  document.title = lang === "en" ? "MELAS ENERGY — LIGHT WITH PURPOSE" : "ΜΕΛΑΣ ΕΝΕΡΓΕΙΑΚΗ — ΦΩΣ ΜΕ ΣΚΟΠΟ";
  try { localStorage.setItem("melasLanguage", lang); } catch (_) {}
}

document.querySelectorAll(".lang-btn").forEach(btn => btn.addEventListener("click", () => setLanguage(btn.dataset.lang)));
document.querySelector(".language-switcher")?.addEventListener("keydown", event => {
  if (!["ArrowLeft", "ArrowRight"].includes(event.key)) return;
  event.preventDefault();
  const nextLanguage = document.documentElement.lang === "el" ? "en" : "el";
  setLanguage(nextLanguage);
  document.querySelector(`.lang-btn[data-lang="${nextLanguage}"]`)?.focus();
});
try { setLanguage(localStorage.getItem("melasLanguage") === "en" ? "en" : "el"); } catch (_) { setLanguage("el"); }

const menuButton = document.querySelector(".menu-toggle");
const nav = document.querySelector(".main-nav");
function closeMenu(){nav.classList.remove("open");menuButton.classList.remove("active");menuButton.setAttribute("aria-expanded","false");document.body.classList.remove("menu-open")}
menuButton.addEventListener("click",()=>{const open=nav.classList.toggle("open");menuButton.classList.toggle("active",open);menuButton.setAttribute("aria-expanded",String(open));document.body.classList.toggle("menu-open",open)});
nav.querySelectorAll("a").forEach(link=>link.addEventListener("click",closeMenu));
window.addEventListener("resize",()=>{if(window.innerWidth>760)closeMenu()});

const observer = "IntersectionObserver" in window ? new IntersectionObserver(entries=>entries.forEach(entry=>{if(entry.isIntersecting){entry.target.classList.add("visible");observer.unobserve(entry.target)}}),{threshold:.12,rootMargin:"0px 0px -35px"}) : null;
document.querySelectorAll(".reveal").forEach(el=>observer ? observer.observe(el) : el.classList.add("visible"));
document.getElementById("year").textContent = new Date().getFullYear();

document.querySelectorAll(".service-card").forEach(card => {
  card.addEventListener("pointermove", event => {
    const rect = card.getBoundingClientRect();
    card.style.setProperty("--mx", `${event.clientX - rect.left}px`);
    card.style.setProperty("--my", `${event.clientY - rect.top}px`);
  });
});

const hero = document.querySelector(".hero");
if (hero && !window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
  window.addEventListener("scroll", () => {
    if (window.scrollY < window.innerHeight) hero.style.backgroundPositionY = `${50 + window.scrollY * .018}%`;
  }, { passive: true });
}
