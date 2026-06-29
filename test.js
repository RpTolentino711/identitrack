
              function toggleForceResolve() {
                  const cb = document.getElementById('force_resolve');
                  if (!cb) return; // If there is a consensus, the checkbox doesn't exist, so don't run this logic!
                  
                  const isChecked = cb.checked;
                  document.getElementById('final_decision').disabled = !isChecked;
                  document.getElementById('submit_final_decision').disabled = !isChecked;
                  
                  // Hide/Show the category dropdown group
                  const catGroup = document.getElementById('decided_category_group');
                  if (catGroup) {
                      catGroup.style.display = isChecked ? 'block' : 'none';
                      const decCat = document.getElementById('decided_category');
                      if (decCat) {
                          decCat.disabled = !isChecked;
                          decCat.required = isChecked;
                      }
                  }
                  
                  // Disable or enable category dynamic fields based on selection
                  const terms = document.getElementById('cat1_terms');
                  if (terms) terms.disabled = !isChecked;
                  
                  document.querySelectorAll('input[name^="cat2_"]').forEach(el => {
                      el.disabled = !isChecked;
                  });

                  if (!isChecked) {
                      const dfc = document.getElementById('dynamicFieldsContainer');
                      if (dfc) dfc.style.display = 'none';
                  } else {
                      if (typeof toggleCategoryFields === 'function') {
                          toggleCategoryFields();
                      }
                  }
              }
              document.addEventListener('DOMContentLoaded', toggleForceResolve);
              

