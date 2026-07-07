$(function () {
  $.ajax({
    url: "../api/get_data.php",
    method: "GET",
    dataType: "json",
    success: function (data) {
      renderSite(data);
    },
    error: function () {
      $("body").prepend(
        '<div class="alert alert-danger text-center mb-0 rounded-0">Could not load website content from the server. Make sure this site is running on a PHP server (e.g. XAMPP) and the api folder is reachable.</div>'
      );
    }
  });
});

function escapeHtml(str) {
  return String(str ?? "")
    .replace(/&/g, "&amp;")
    .replace(/"/g, "&quot;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
}

// Safe fallback so a blank/empty field saved in the dashboard never breaks the page.
function safe(str) {
  return String(str ?? "");
}

function renderSite(data) {

  /* ---------- TOP BAR ---------- */
  $("#tbPhone span").text(safe(data.topbar.phone));
  $("#tbPhone").attr("href", "tel:" + safe(data.topbar.phone).replace(/\s+/g, ""));
  $("#tbEmail span").text(safe(data.topbar.email));
  $("#tbEmail").attr("href", "mailto:" + safe(data.topbar.email));
  $("#tbInsta").attr("href", "https://instagram.com/" + safe(data.topbar.insta).replace("@", ""));
  $("#tbFb").attr("href", safe(data.topbar.facebook) || "#");
  $("#tbLinkedin").attr("href", safe(data.topbar.linkedin) || "#");

  $("#navCallBtn").attr("href", "tel:" + safe(data.topbar.phone).replace(/\s+/g, ""));
  $("#waFloat").attr("href", "https://wa.me/" + safe(data.topbar.phone).replace(/\D/g, ""));

  $("#footInsta").attr("href", "https://instagram.com/" + safe(data.topbar.insta).replace("@", ""));
  $("#footFb").attr("href", safe(data.topbar.facebook) || "#");
  $("#footLinkedin").attr("href", safe(data.topbar.linkedin) || "#");
  $("#footPhone").text(safe(data.topbar.phone));
  $("#footEmail").text(safe(data.topbar.email));
  $("#footAddress").text(safe(data.contact.address));

  /* ---------- HERO / ABOUT ---------- */
  $("#heroTitle").text(data.hero.title);
  $("#heroSubtitle").text(data.hero.subtitle);
  $("#aboutText").text(data.about.text);

  /* ---------- SERVICES ---------- */
  let servicesHtml = "";
  data.services.forEach(function (s) {
    servicesHtml += `
      <div class="col-md-6 col-lg-4 reveal">
        <div class="service-card text-start">
          <div class="icon-box"><i class="bi ${s.icon}"></i></div>
          <h5>${escapeHtml(s.title)}</h5>
          <p class="text-muted mb-0">${escapeHtml(s.desc)}</p>
        </div>
      </div>`;
  });
  $("#servicesRow").html(servicesHtml);

  /* ---------- PRODUCTS ---------- */
  let productsHtml = "";
  data.products.forEach(function (p) {
    productsHtml += `
      <div class="col-sm-6 col-lg-4 reveal">
        <div class="product-card text-start">
          <div class="img-wrap"><img src="${escapeHtml(p.img)}" alt="${escapeHtml(p.name)}"></div>
          <div class="body">
            <h5>${escapeHtml(p.name)}</h5>
            <p>${escapeHtml(p.desc)}</p>
          </div>
        </div>
      </div>`;
  });
  $("#productsRow").html(productsHtml);

  /* ---------- ACCESSORIES ---------- */
  let accHtml = "";
  data.accessories.forEach(function (a) {
    accHtml += `
      <div class="col-sm-6 col-lg-4 reveal">
        <div class="accessory-tile">
          <img src="${escapeHtml(a.img)}" alt="${escapeHtml(a.name)}">
          <div class="overlay"><h5>${escapeHtml(a.name)}</h5></div>
        </div>
      </div>`;
  });
  $("#accessoriesRow").html(accHtml);

  /* ---------- GALLERY ---------- */
  let galHtml = "";
  data.gallery.forEach(function (g) {
    galHtml += `
      <div class="col-sm-6 col-lg-4 reveal">
        <div class="gallery-item" data-img="${escapeHtml(g.img)}" data-caption="${escapeHtml(g.caption)}">
          <img src="${escapeHtml(g.img)}" alt="${escapeHtml(g.caption)}">
          <div class="g-overlay"><i class="bi bi-zoom-in"></i></div>
          <div class="g-caption">${escapeHtml(g.caption)}</div>
        </div>
      </div>`;
  });
  $("#galleryRow").html(galHtml);

  /* ---------- WHY US ---------- */
  let whyHtml = "";
  data.whyus.forEach(function (w) {
    whyHtml += `
      <div class="col-md-6 col-lg-4 reveal">
        <div class="why-card text-start">
          <div class="icon-box"><i class="bi ${w.icon}"></i></div>
          <h5>${escapeHtml(w.title)}</h5>
          <p class="text-muted mb-0">${escapeHtml(w.desc)}</p>
        </div>
      </div>`;
  });
  $("#whyRow").html(whyHtml);

  /* ---------- CONTACT ---------- */
  $("#contactAddress").text(data.contact.address);
  $("#contactPhone").text(data.contact.phone);
  $("#contactEmail").text(data.contact.email);
  $("#mapFrame").attr("src", data.contact.mapEmbed);

  $("#yearNow").text(new Date().getFullYear());

  /* ---------- SCROLL REVEAL ---------- */
  function revealOnScroll() {
    $(".reveal").each(function () {
      const top = $(this).offset().top;
      const winBottom = $(window).scrollTop() + $(window).height() - 80;
      if (winBottom > top) $(this).addClass("active");
    });
  }
  revealOnScroll();
  $(window).on("scroll", revealOnScroll);

  /* ---------- BACK TO TOP ---------- */
  $(window).on("scroll", function () {
    if ($(this).scrollTop() > 400) $("#backToTop").fadeIn();
    else $("#backToTop").fadeOut();
  });
  $("#backToTop").on("click", function () {
    $("html, body").animate({ scrollTop: 0 }, 500);
  });

  /* ---------- SMOOTH SCROLL + ACTIVE NAV ---------- */
  $('a.nav-link[href^="#"]').on("click", function (e) {
    e.preventDefault();
    const target = $($(this).attr("href"));
    if (target.length) {
      $("html, body").animate({ scrollTop: target.offset().top - 70 }, 600);
      $(".navbar-collapse").collapse("hide");
    }
  });

  const sections = $("section[id]");
  $(window).on("scroll", function () {
    const scrollPos = $(window).scrollTop() + 100;
    sections.each(function () {
      const top = $(this).offset().top;
      const bottom = top + $(this).outerHeight();
      const id = $(this).attr("id");
      if (scrollPos >= top && scrollPos < bottom) {
        $(".nav-link").removeClass("active");
        $('.nav-link[href="#' + id + '"]').addClass("active");
      }
    });
  });

  /* ---------- LIGHTBOX ---------- */
  $(document).on("click", ".gallery-item", function () {
    const img = $(this).data("img");
    const caption = $(this).data("caption");
    $("#lightboxImg").attr("src", img);
    $("#lightboxCaption").text(caption);
    $("#lightboxOverlay").fadeIn(200).css("display", "flex");
  });
  $(".lb-close, #lightboxOverlay").on("click", function (e) {
    if (e.target.id === "lightboxOverlay" || $(e.target).hasClass("lb-close") || $(e.target).parent().hasClass("lb-close")) {
      $("#lightboxOverlay").fadeOut(200);
    }
  });

  /* ---------- CONTACT FORM ---------- */
  $("#contactForm").on("submit", function (e) {
    e.preventDefault();
    const form = this;
    const $btn = $(form).find("button[type=submit]").prop("disabled", true);

    $.ajax({
      url: "../api/send_message.php",
      method: "POST",
      dataType: "json",
      data: {
        name: $("#cfName").val(),
        mobile: $("#cfPhone").val(),
        email: $("#cfEmail").val(),
        message: $("#cfMessage").val()
      },
      success: function (res) {
        if (res.ok) {
          $("#formSuccess").removeClass("d-none");
          form.reset();
          setTimeout(() => $("#formSuccess").addClass("d-none"), 4000);
        } else {
          alert(res.error || "Something went wrong. Please try again.");
        }
      },
      error: function () {
        alert("Could not send your message. Please check your connection and try again.");
      },
      complete: function () {
        $btn.prop("disabled", false);
      }
    });
  });
}
