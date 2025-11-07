document.addEventListener("DOMContentLoaded", () => {
    console.log(" BloodBank site loaded and ready.");

    // Modal elements
    const modal = document.getElementById("choiceModal");
    const modalTitle = document.getElementById("modalTitle");
    const loginBtn = document.getElementById("loginBtn");
    const registerBtn = document.getElementById("registerBtn");
    const closeBtn = modal.querySelector(".close");

    const donorChoice = modal.querySelector("#donorChoice");
    const hospitalChoice = modal.querySelector("#hospitalChoice");

    let isLoginMode = false;

    // Open modal in Login or Register mode
    if (loginBtn) {
        loginBtn.addEventListener("click", () => {
            modal.style.display = "block";
            modalTitle.textContent = "Login As";
            isLoginMode = true;
        });
    }

    if (registerBtn) {
        registerBtn.addEventListener("click", () => {
            modal.style.display = "block";
            modalTitle.textContent = "Register As";
            isLoginMode = false;
        });
    }

    // Close modal
    if (closeBtn) {
        closeBtn.addEventListener("click", () => modal.style.display = "none");
    }
    window.addEventListener("click", (e) => {
        if (e.target === modal) modal.style.display = "none";
    });

    // Redirect helper
    const redirect = (role) => {
        modal.style.display = "none";
        if (isLoginMode) {
            window.location.href = "signin.php";
        } else if (role === "donor") {
            window.location.href = "donor_registration.php";
        } else if (role === "hospital") {
            window.location.href = "hospital_registration.php";
        }
    };

    // Attach event listeners
    if (donorChoice) donorChoice.addEventListener("click", () => redirect("donor"));
    if (hospitalChoice) hospitalChoice.addEventListener("click", () => redirect("hospital"));

    
    // Form Validation 
 
    const forms = document.querySelectorAll("form");
    forms.forEach(form => {
        form.addEventListener("submit", (e) => {
            let valid = true;
            let errors = [];
            const inputs = form.querySelectorAll("input, select");

            inputs.forEach(input => {
                if (input.hasAttribute("required") && !input.value.trim()) {
                    valid = false;
                    errors.push(`${input.name || 'This field'} is required.`);
                    input.style.borderColor = "red";
                } else {
                    input.style.borderColor = "#ccc";
                }

                if (input.type === "email" && input.value.trim()) {
                    const pattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!pattern.test(input.value)) {
                        valid = false;
                        errors.push("Please enter a valid email address.");
                        input.style.borderColor = "red";
                    }
                }

                if (input.type === "password" && input.value.trim() && input.value.length < 6) {
                    valid = false;
                    errors.push("Password must be at least 6 characters long.");
                    input.style.borderColor = "red";
                }

                if (input.name.toLowerCase().includes("phone") && input.value.trim()) {
                    const phonePattern = /^[0-9]{7,15}$/;
                    if (!phonePattern.test(input.value)) {
                        valid = false;
                        errors.push("Enter a valid phone number (7–15 digits).");
                        input.style.borderColor = "red";
                    }
                }
            });

            if (!valid) {
                e.preventDefault();
                alert(" Please fix the following errors:\n\n" + errors.join("\n"));
            } else {
                if (!confirm("Submit form?")) e.preventDefault();
            }
        });
    });
});
