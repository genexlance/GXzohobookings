(function(){'use strict';
  document.addEventListener('DOMContentLoaded',function(){
    var form=document.querySelector('.gx-zb-book-form');
    if(!form) return;
    var serviceSelect=document.getElementById('gx-zb-book-service');
    var staffSelect=document.getElementById('gx-zb-book-staff');
    var dateInput=document.getElementById('gx-zb-book-date');
    var slotsContainer=document.getElementById('gx-zb-book-slots');
    var hiddenSlot=document.getElementById('gx-zb-book-slot');
    var nameInput=document.getElementById('gx-zb-book-name');
    var emailInput=document.getElementById('gx-zb-book-email');
    var phoneInput=document.getElementById('gx-zb-book-phone');
    var submitBtn=document.getElementById('gx-zb-book-submit');
    var priceNote=document.getElementById('gx-zb-pay-note');
    var staffField=document.getElementById('gx-zb-staff-field');
    var resourceField=document.getElementById('gx-zb-resource-field');
    var resourceSelect=document.getElementById('gx-zb-book-resource');
    var resourceTimeField=document.getElementById('gx-zb-resource-time-field');
    var resourceTime=document.getElementById('gx-zb-book-time');
    var cfg=window.gxZbBook||{};
    var ajaxUrl=cfg.ajaxUrl||'';
    var nonce=cfg.nonce||'';
    function ajaxPost(action,data,cb){
      var xhr=new XMLHttpRequest();
      xhr.open('POST',ajaxUrl,true);
      xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded; charset=UTF-8');
      xhr.onreadystatechange=function(){
        if(xhr.readyState===4){
          if(xhr.status>=200&&xhr.status<300){
            try{
              var res=JSON.parse(xhr.responseText);
              if(res.success&&res.data) cb(null,res.data);
              else cb(new Error('Invalid response'),null);
            }catch(e){
              cb(e,null);
            }
          }else{
            cb(new Error('HTTP error'),null);
          }
        }
      };
      var params='action='+encodeURIComponent(action)+'&_ajax_nonce='+encodeURIComponent(nonce);
      for(var key in data){
        if(data.hasOwnProperty(key)){
          params+='&'+encodeURIComponent(key)+'='+encodeURIComponent(data[key]);
        }
      }
      xhr.send(params);
    }
    function updateFields(){
      var slot=document.getElementById('gx-zb-custom-fields-slot');
      if(!slot||!serviceSelect) return;
      var sidx=serviceSelect.selectedIndex;
      var serviceId=serviceSelect.options[sidx]?serviceSelect.options[sidx].value:'';
      // A server-rendered field set (preselected service) already exists — leave it.
      if(document.querySelector('.gx-zb-custom-fields')){ return; }
      if(!serviceId){ slot.innerHTML=''; return; }
      ajaxPost('gx_zb_fields',{service_id:serviceId},function(err,data){
        slot.innerHTML=(!err&&data&&data.html)?data.html:'';
      });
    }
    function selectedOption(){
      var idx=serviceSelect?serviceSelect.selectedIndex:-1;
      return (serviceSelect&&idx>=0)?serviceSelect.options[idx]:null;
    }
    function isResourceService(){
      var opt=selectedOption();
      return !!(opt&&opt.getAttribute('data-service-type')==='resource');
    }
    // Toggle the form between staff mode and resource mode.
    function applyMode(){
      var res=isResourceService();
      if(staffField) staffField.style.display=res?'none':'';
      if(slotsContainer) slotsContainer.style.display=res?'none':'';
      if(resourceField) resourceField.style.display=res?'':'none';
      if(resourceTimeField) resourceTimeField.style.display=res?'':'none';
      if(staffSelect) staffSelect.required=!res;
      if(resourceSelect) resourceSelect.required=res;
      if(res){
        // updateStaff (which also refreshes the price note) is skipped in
        // resource mode, so refresh the price note here.
        var opt=selectedOption();
        var cost=opt?parseFloat(opt.getAttribute('data-cost')):0;
        if(priceNote&&opt&&opt.value){
          priceNote.style.display='block';
          priceNote.innerHTML=(cost>0)?('<span class="gx-zb-price">'+cost.toFixed(2)+'</span> &mdash; Secure payment via Stripe'):'<span class="gx-zb-price">Free</span>';
        }
        updateResources();
      } else { checkResourceReady(); }
    }
    function updateResources(){
      if(!resourceSelect) return;
      var opt=selectedOption();
      var serviceId=opt?opt.value:'';
      if(!serviceId){ resourceSelect.innerHTML='<option value="">Select service first</option>'; resourceSelect.disabled=true; return; }
      resourceSelect.disabled=true;
      resourceSelect.innerHTML='<option value="">Loading...</option>';
      ajaxPost('gx_zb_resources',{service_id:serviceId},function(err,data){
        resourceSelect.disabled=false;
        if(err||!data){ resourceSelect.innerHTML='<option value="">Error loading resources</option>'; return; }
        var html='<option value="">Select resource</option>';
        for(var i=0;i<data.length;i++){ html+='<option value="'+data[i].id+'">'+data[i].name+'</option>'; }
        resourceSelect.innerHTML=html;
      });
    }
    // Enable submit once a resource booking has resource + date + time.
    function checkResourceReady(){
      if(!isResourceService()) return;
      var ok=resourceSelect&&resourceSelect.value&&dateInput&&dateInput.value&&resourceTime&&resourceTime.value;
      if(submitBtn) submitBtn.disabled=!ok;
    }
    function updateStaff(){
      if(isResourceService()){ return; }
      var idx=serviceSelect.selectedIndex;
      var opt=serviceSelect.options[idx];
      var serviceId=opt?opt.value:'';
      var cost=opt?opt.getAttribute('data-cost'):'0';
      if(priceNote){
        var costNum=parseFloat(cost);
        if(serviceId){
          priceNote.style.display='block';
          if(costNum>0){
            priceNote.innerHTML='<span class="gx-zb-price">'+costNum.toFixed(2)+'</span> &mdash; Secure payment via Stripe';
          }else{
            priceNote.innerHTML='<span class="gx-zb-price">Free</span>';
          }
        }else{
          priceNote.style.display='none';
          priceNote.innerHTML='';
        }
      }
      if(!serviceId){ staffSelect.innerHTML='<option value="">Select service first</option>'; return; }
      staffSelect.disabled=true;
      staffSelect.innerHTML='<option value="">Loading...</option>';
      ajaxPost('gx_zb_staff',{service_id:serviceId},function(err,data){
        staffSelect.disabled=false;
        if(err){ staffSelect.innerHTML='<option value="">Error loading staff</option>'; return; }
        var html='<option value="">Select staff</option>';
        for(var i=0;i<data.length;i++){
          html+='<option value="'+data[i].id+'">'+data[i].name+'</option>';
        }
        staffSelect.innerHTML=html;
      });
    }
    function updateSlots(){
      if(isResourceService()){ return; }
      var sidx=serviceSelect.selectedIndex;
      var serviceId=serviceSelect.options[sidx]?serviceSelect.options[sidx].value:'';
      var staffId=staffSelect.value;
      var dateVal=dateInput.value;
      if(!serviceId||!staffId||!dateVal){ slotsContainer.innerHTML=''; hiddenSlot.value=''; submitBtn.disabled=true; return; }
      slotsContainer.innerHTML='<p>Loading slots...</p>';
      ajaxPost('gx_zb_slots',{service_id:serviceId,staff_id:staffId,date:dateVal},function(err,data){
        if(err){
          slotsContainer.innerHTML='<p>Error loading slots</p>';
          return;
        }
        if(!data||data.length===0){
          slotsContainer.innerHTML='<p>No available slots</p>';
          hiddenSlot.value='';
          submitBtn.disabled=true;
          return;
        }
        var html='';
        for(var i=0;i<data.length;i++){
          html+='<button type="button" class="gx-zb-slot" data-slot="'+data[i]+'">'+data[i]+'</button>';
        }
        slotsContainer.innerHTML=html;
        var slotBtns=slotsContainer.querySelectorAll('.gx-zb-slot');
        for(var j=0;j<slotBtns.length;j++){
          slotBtns[j].addEventListener('click',slotClickHandler);
        }
        hiddenSlot.value='';
        submitBtn.disabled=true;
      });
    }
    function slotClickHandler(e){
      var btn=e.currentTarget;
      var slots=slotsContainer.querySelectorAll('.gx-zb-slot');
      for(var i=0;i<slots.length;i++){
        slots[i].classList.remove('is-selected');
      }
      btn.classList.add('is-selected');
      hiddenSlot.value=btn.getAttribute('data-slot');
      submitBtn.disabled=false;
    }
    if(serviceSelect){
      serviceSelect.addEventListener('change',function(){
        applyMode();
        updateStaff();
        updateFields();
        updateSlots();
      });
    }
    if(staffSelect){
      staffSelect.addEventListener('change',function(){
        updateSlots();
      });
    }
    if(dateInput){
      dateInput.addEventListener('change',function(){
        updateSlots();
        checkResourceReady();
      });
    }
    if(resourceSelect){
      resourceSelect.addEventListener('change',checkResourceReady);
    }
    if(resourceTime){
      resourceTime.addEventListener('change',checkResourceReady);
      resourceTime.addEventListener('input',checkResourceReady);
    }
    if(serviceSelect&&serviceSelect.options.length>1){
      applyMode();
      updateStaff();
      updateFields();
    }
  });
})();