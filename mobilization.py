import streamlit as st
import pandas as pd
from database import db

def mobilization_module():
    st.header("🚧 Project Mobilization[file:142]")
    
    # Metrics
    projects_df = db.get_projects()
    total = len(projects_df)
    mobilized = len(projects_df[projects_df['status'] == 'Mobilised'])
    
    col1, col2, col3 = st.columns(3)
    col1.metric("Total Projects", total)
    col2.metric("Mobilised", mobilized, f"{mobilized/total*100:.0f}%" if total else 0)
    
    # Progress chart
    progress_data = []
    for _, row in projects_df.iterrows():
        progress = 0
        if row['pa_number']: progress += 33
        if row['bca_status'] != 'AWAITING': progress += 33
        if row['status'] in ['Ready', 'Mobilised']: progress += 34
        progress_data.append({"Project": row['name'][:15], "Progress": progress})
    
    st.subheader("📈 Mobilization Progress")
    st.bar_chart(pd.DataFrame(progress_data).set_index("Project"))
    
    # Projects table
    st.subheader("📋 All Projects")
    st.dataframe(projects_df[['name', 'client_name', 'city', 'pa_number', 'bca_status', 'status']], 
                use_container_width=True)
    
    # Update form
    st.subheader("🔄 Update Project")
    project_options = {row['id']: f"{row['name']} ({row['city']})" for _, row in projects_df.iterrows()}
    selected_id = st.selectbox("Select Project", list(project_options.keys()), 
                              format_func=lambda x: project_options[x])
    
    selected_project = projects_df[projects_df['id'] == selected_id].iloc[0]
    
    col1, col2, col3 = st.columns(3)
    with col1:
        new_pa = st.text_input("PA Number", selected_project['pa_number'])
    with col2:
        new_bca = st.text_input("BCA Status", selected_project['bca_status'])
    with col3:
        new_status = st.selectbox("Status", ["Pending", "In Process", "Ready", "Mobilised"], 
                                 index=["Pending", "In Process", "Ready", "Mobilised"].index(selected_project['status']))
    
    if st.button("💾 Update", type="primary"):
        db.update_project(selected_id, pa_number=new_pa, bca_status=new_bca, status=new_status)
        st.success("✅ Updated!")
        st.rerun()
