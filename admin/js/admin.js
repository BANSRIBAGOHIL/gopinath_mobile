const API = "../api/";
let siteData = null;
let csrfToken = null;

// Attach CSRF token to every state-changing AJAX request automatically
$(document).ajaxSend(function (event, jqxhr, settings) {
  if (settings.type === "POST" && csrfToken) {
    jqxhr.setRequestHeader("X-CSRF-Token", csrfToken);
  }
});

/* ============================================================
   LOGIN
============================================================ */
$(function () {
  checkSession();

  $("#loginForm").on("submit", function (e) {
    e.preventDefault();
    const username = $("#loginUser").val().trim();
    const password = $("#loginPass").val().trim();
    $.ajax({
      url: API + "login.php",
      method: "POST",
      contentType: "application/json",
      data: JSON.stringify({ username, password }),
      success: function (res) {
        $("#loginError").addClass("d-none");
        csrfToken = res.csrf_token;
        showDashboard();
      },
      error: function () {
        $("#loginError").removeClass("d-none");
      }
    });
  });

  $("#logoutBtn").on("click", function (e) {
    e.preventDefault();
    $.post(API + "logout.php", function () {
      location.reload();
    });
  });

  $(".sidebar-nav .nav-link[data-tab]").on("click", function (e) {
    e.preventDefault();
    const tab = $(this).data("tab");
    $(".sidebar-nav .nav-link").removeClass("active");
    $(this).addClass("active");
    $(".tab-pane").removeClass("active");
    $("#" + tab).addClass("active");
    $("#pageTitle").text($(this).text().trim());
  });

  $("#resetBtn").on("click", function () {
    if (!confirm("This will reset ALL website content to the original defaults. Continue?")) return;
    $.post(API + "reset_data.php", function (res) {
      if (res.success) {
        siteData = res.data;
        fillForms();
        toast("Website content reset to default.");
      }
    });
  });

  bindStaticForms();
  bindAddButtons();
});

function checkSession() {
  $.get(API + "check_session.php", function (res) {
    if (res.logged_in) {
      csrfToken = res.csrf_token;
      showDashboard();
    } else {
      $("#loginScreen").removeClass("d-none");
    }
  }).fail(function () {
    $("#loginScreen").removeClass("d-none");
  });
}

function showDashboard() {
  $("#loginScreen").addClass("d-none");
  $("#dashboardApp").removeClass("d-none");
  loadData();
}

function loadData() {
  $.get(API + "get_data.php", function (data) {
    siteData = data;
    fillForms();
  }).fail(function () {
    toast("Could not load data from server. Check that PHP is running.", true);
  });
}

function toast(msg, isError) {
  $("#toastBox")
    .removeClass("d-none alert-success alert-danger")
    .addClass(isError ? "alert-danger" : "alert-success")
    .find("#toastMsg")
    .text(msg);
  $("#toastBox").removeClass("d-none");
  setTimeout(() => $("#toastBox").addClass("d-none"), 3000);
}

function saveAll(successMsg) {
  $.ajax({
    url: API + "save_data.php",
    method: "POST",
    contentType: "application/json",
    data: JSON.stringify(siteData),
    success: function () {
      toast(successMsg || "Saved successfully.");
    },
    error: function () {
      toast("Could not save. Please login again.", true);
    }
  });
}

/* ============================================================
   FILL STATIC FORMS
============================================================ */
function fillForms() {
  $("#f_phone").val(siteData.topbar.phone);
  $("#f_email").val(siteData.topbar.email);
  $("#f_insta").val(siteData.topbar.insta);
  $("#f_fb").val(siteData.topbar.facebook);
  $("#f_linkedin").val(siteData.topbar.linkedin);

  $("#f_heroTitle").val(siteData.hero.title);
  $("#f_heroSub").val(siteData.hero.subtitle);
  $("#f_aboutText").val(siteData.about.text);

  $("#f_address").val(siteData.contact.address);
  $("#f_cphone").val(siteData.contact.phone);
  $("#f_cemail").val(siteData.contact.email);
  $("#f_map").val(siteData.contact.mapEmbed);

  renderServices();
  renderProducts();
  renderAccessories();
  renderGallery();
  renderWhy();
}

function bindStaticForms() {
  $("#saveTopbar").on("click", function () {
    siteData.topbar = {
      phone: $("#f_phone").val(),
      email: $("#f_email").val(),
      insta: $("#f_insta").val(),
      facebook: $("#f_fb").val(),
      linkedin: $("#f_linkedin").val()
    };
    saveAll("Top bar updated.");
  });

  $("#saveHero").on("click", function () {
    siteData.hero = { title: $("#f_heroTitle").val(), subtitle: $("#f_heroSub").val() };
    siteData.about = { text: $("#f_aboutText").val() };
    saveAll("Hero & about updated.");
  });

  $("#saveContact").on("click", function () {
    siteData.contact = {
      address: $("#f_address").val(),
      phone: $("#f_cphone").val(),
      email: $("#f_cemail").val(),
      mapEmbed: $("#f_map").val()
    };
    saveAll("Contact info updated.");
  });
}

/* ============================================================
   GENERIC LIST RENDERERS (services, products, accessories, gallery, whyus)
============================================================ */
function renderServices() {
  const $list = $("#servicesList").empty();
  siteData.services.forEach(function (item, i) {
    const $card = $(`
      <div class="item-card">
        <div class="row g-3">
          <div class="col-md-2">
            <input type="text" class="form-control mb-2 f-icon" placeholder="bi-tools icon class" value="${escapeHtml(item.icon)}">
            <small class="text-muted">Bootstrap Icons class</small>
          </div>
          <div class="col-md-9">
            <input type="text" class="form-control mb-2 f-title" placeholder="Title" value="${escapeHtml(item.title)}">
            <textarea class="form-control f-desc" rows="2" placeholder="Description">${escapeHtml(item.desc)}</textarea>
          </div>
          <div class="col-md-1 text-end"><button class="btn btn-sm btn-outline-danger del-item"><i class="bi bi-trash"></i></button></div>
        </div>
      </div>`);
    $card.find(".del-item").on("click", function () {
      siteData.services.splice(i, 1);
      renderServices();
    });
    $card.find("input,textarea").on("change", function () {
      siteData.services[i] = {
        icon: $card.find(".f-icon").val(),
        title: $card.find(".f-title").val(),
        desc: $card.find(".f-desc").val()
      };
    });
    $list.append($card);
  });
  appendSaveButton($list, "services", "Save Services");
}

function renderProducts() {
  const $list = $("#productsList").empty();
  siteData.products.forEach(function (item, i) {
    const $card = makeImageItemCard(item, i, "products", ["name", "desc"], true);
    $list.append($card);
  });
  appendSaveButton($list, "products", "Save Products");
}

function renderAccessories() {
  const $list = $("#accessoriesList").empty();
  siteData.accessories.forEach(function (item, i) {
    const $card = makeImageItemCard(item, i, "accessories", ["name"], true);
    $list.append($card);
  });
  appendSaveButton($list, "accessories", "Save Accessories");
}

function renderGallery() {
  const $list = $("#galleryList").empty();
  siteData.gallery.forEach(function (item, i) {
    const $card = makeImageItemCard(item, i, "gallery", ["caption"], true);
    $list.append($card);
  });
  appendSaveButton($list, "gallery", "Save Gallery");
}

function renderWhy() {
  const $list = $("#whyList").empty();
  siteData.whyus.forEach(function (item, i) {
    const $card = $(`
      <div class="item-card">
        <div class="row g-3">
          <div class="col-md-2">
            <input type="text" class="form-control mb-2 f-icon" placeholder="bi-stars icon class" value="${escapeHtml(item.icon)}">
            <small class="text-muted">Bootstrap Icons class</small>
          </div>
          <div class="col-md-9">
            <input type="text" class="form-control mb-2 f-title" placeholder="Title" value="${escapeHtml(item.title)}">
            <textarea class="form-control f-desc" rows="2" placeholder="Description">${escapeHtml(item.desc)}</textarea>
          </div>
          <div class="col-md-1 text-end"><button class="btn btn-sm btn-outline-danger del-item"><i class="bi bi-trash"></i></button></div>
        </div>
      </div>`);
    $card.find(".del-item").on("click", function () {
      siteData.whyus.splice(i, 1);
      renderWhy();
    });
    $card.find("input,textarea").on("change", function () {
      siteData.whyus[i] = {
        icon: $card.find(".f-icon").val(),
        title: $card.find(".f-title").val(),
        desc: $card.find(".f-desc").val()
      };
    });
    $list.append($card);
  });
  appendSaveButton($list, "whyus", "Save Why-Choose-Us");
}

/* builds a card with image preview + upload + text fields (name/desc/caption) */
function makeImageItemCard(item, index, key, textFields, hasImg) {
  let fieldsHtml = "";
  textFields.forEach(function (f) {
    const label = f.charAt(0).toUpperCase() + f.slice(1);
    fieldsHtml += `<input type="text" class="form-control mb-2 f-${f}" placeholder="${label}" value="${escapeHtml(item[f] || "")}">`;
  });

  const $card = $(`
    <div class="item-card">
      <div class="row g-3 align-items-start">
        <div class="col-md-3">
          <img class="item-thumb mb-2" src="${item.img || ""}" alt="">
          <input type="file" class="form-control form-control-sm f-upload" accept="image/*">
          <input type="text" class="form-control form-control-sm mt-2 f-imgurl" placeholder="Or paste image URL" value="${escapeHtml(item.img || "")}">
        </div>
        <div class="col-md-8">${fieldsHtml}</div>
        <div class="col-md-1 text-end"><button class="btn btn-sm btn-outline-danger del-item"><i class="bi bi-trash"></i></button></div>
      </div>
    </div>`);

  $card.find(".del-item").on("click", function () {
    siteData[key].splice(index, 1);
    rerender(key);
  });

  function syncFields() {
    const updated = { img: $card.find(".f-imgurl").val() };
    textFields.forEach(function (f) { updated[f] = $card.find(".f-" + f).val(); });
    siteData[key][index] = updated;
  }

  $card.find(".f-imgurl, input.f-name, input.f-desc, input.f-caption, textarea").on("change", syncFields);
  $card.find(".f-imgurl").on("change", function () {
    $card.find(".item-thumb").attr("src", $(this).val());
    syncFields();
  });

  $card.find(".f-upload").on("change", function () {
    const file = this.files[0];
    if (!file) return;
    const formData = new FormData();
    formData.append("image", file);
    $.ajax({
      url: API + "upload.php",
      method: "POST",
      data: formData,
      contentType: false,
      processData: false,
      success: function (res) {
        if (res.success) {
          $card.find(".f-imgurl").val(res.url);
          $card.find(".item-thumb").attr("src", res.url);
          syncFields();
          toast("Image uploaded.");
        } else {
          toast(res.message || "Upload failed.", true);
        }
      },
      error: function () { toast("Upload failed. Check server.", true); }
    });
  });

  return $card;
}

function rerender(key) {
  if (key === "products") renderProducts();
  if (key === "accessories") renderAccessories();
  if (key === "gallery") renderGallery();
}

function appendSaveButton($list, key, label) {
  const $btn = $(`<button class="btn btn-primary mt-2">${label}</button>`);
  $btn.on("click", function () { saveAll(label + " updated."); });
  $list.after($btn);
}

/* ============================================================
   ADD NEW ITEM BUTTONS
============================================================ */
function bindAddButtons() {
  $("#addService").on("click", function () {
    if (!siteData) return;
    siteData.services.push({ icon: "bi-star", title: "New Service", desc: "Describe this service." });
    renderServices();
  });
  $("#addProduct").on("click", function () {
    if (!siteData) return;
    siteData.products.push({ name: "New Product", img: "", desc: "Product description." });
    renderProducts();
  });
  $("#addAccessory").on("click", function () {
    if (!siteData) return;
    siteData.accessories.push({ name: "New Accessory", img: "" });
    renderAccessories();
  });
  $("#addGallery").on("click", function () {
    if (!siteData) return;
    siteData.gallery.push({ img: "", caption: "New Photo" });
    renderGallery();
  });
  $("#addWhy").on("click", function () {
    if (!siteData) return;
    siteData.whyus.push({ icon: "bi-star", title: "New Reason", desc: "Why customers choose us." });
    renderWhy();
  });
}

function escapeHtml(str) {
  return String(str || "")
    .replace(/&/g, "&amp;")
    .replace(/"/g, "&quot;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
}
